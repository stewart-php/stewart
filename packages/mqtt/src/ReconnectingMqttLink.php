<?php

declare(strict_types=1);

namespace Stewart\Mqtt;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Mqtt\MqttQos;
use Stewart\Mqtt\Connection\MqttConnection;
use Stewart\Mqtt\Connection\MqttConnector;
use Stewart\Mqtt\Exception\MqttClientException;
use Stewart\Runtime\Broker\Mqtt\MqttLink;
use Stewart\Runtime\Broker\Mqtt\MqttMessageRouter;
use Stewart\Runtime\Config\MqttConfig;
use Stewart\Support\Time\Deadlines;
use Throwable;

use function Amp\async;

final class ReconnectingMqttLink implements MqttLink
{
    /** @var array<string, true> */
    private array $activeFilters = [];

    private ?MqttConnection $connection = null;

    private bool $started = false;

    private readonly DeferredCancellation $stop;

    public function __construct(
        private readonly MqttConfig $config,
        private readonly MqttConnector $connector,
        private readonly OutboundMqttQueue $outbound,
        private readonly Deadlines $deadlines,
        private readonly LoggerInterface $logger,
    ) {
        $this->stop = new DeferredCancellation();
    }

    public function startInBackground(MqttMessageRouter $router): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;
        async(fn() => $this->keepConnected($router));
    }

    public function publishMessage(MqttMessage $message): void
    {
        if ($message->qos === MqttQos::AtLeastOnce && $this->outbound->canHoldMessages()) {
            $this->publishAtLeastOnce($message);

            return;
        }

        if ($this->connection === null) {
            $this->logger->warning('MQTT message dropped while the server is unreachable', ['topic' => $message->topic]);

            return;
        }

        try {
            $this->connection->publishMessage($message)->ignore();
        } catch (MqttClientException $e) {
            $this->logger->warning('MQTT message dropped', ['topic' => $message->topic, 'exception' => $e]);
        }
    }

    public function subscribeFilter(string $topicFilter): void
    {
        $this->activeFilters[$topicFilter] = true;

        if ($this->connection !== null) {
            $this->subscribeOn($this->connection, $topicFilter);
        }
    }

    public function unsubscribeFilter(string $topicFilter): void
    {
        unset($this->activeFilters[$topicFilter]);

        try {
            $this->connection?->unsubscribeFilter($topicFilter)->ignore();
        } catch (MqttClientException) {
            // A lost connection drops every subscription with it.
        }
    }

    public function close(): void
    {
        $this->stop->cancel();
        $this->connection?->disconnect();
    }

    private function keepConnected(MqttMessageRouter $router): void
    {
        $failures = 0;

        while (!$this->stop->getCancellation()->isRequested()) {
            $connection = $this->attemptConnection($router, $failures + 1);

            if ($connection === null) {
                $this->waitBeforeReconnecting(++$failures);

                continue;
            }

            $failures = 0;
            $this->runSession($connection);
            $this->waitBeforeReconnecting(1);
        }
    }

    private function attemptConnection(MqttMessageRouter $router, int $attempt): ?MqttConnection
    {
        try {
            $connection = $this->connector->openConnection($this->config, $router->routeMqttMessage(...), $this->stop->getCancellation());
        } catch (Throwable $e) {
            if (!$this->stop->getCancellation()->isRequested()) {
                $this->logger->log($attempt === 1 ? LogLevel::ERROR : LogLevel::WARNING, 'Could not reach the MQTT server', [
                    'server' => (string) $this->config->serverUrl,
                    'attempt' => $attempt,
                    'exception' => $e,
                ]);
            }

            return null;
        }

        if ($this->stop->getCancellation()->isRequested()) {
            $connection->disconnect();

            return null;
        }

        return $connection;
    }

    private function runSession(MqttConnection $connection): void
    {
        $this->connection = $connection;
        $this->logger->info('Connected to the MQTT server', ['server' => (string) $this->config->serverUrl, 'filters' => \count($this->activeFilters)]);

        foreach (array_keys($this->activeFilters) as $topicFilter) {
            $this->subscribeOn($connection, (string) $topicFilter);
        }

        $this->outbound->replayPendingMessages(function (int $sequence, MqttMessage $message) use ($connection): void {
            $this->sendAtLeastOnce($connection, $sequence, $message);
        });

        $lostBecause = $connection->awaitClosed();
        $this->connection = null;

        if (!$this->stop->getCancellation()->isRequested()) {
            $this->logger->warning('Lost the MQTT connection', ['server' => (string) $this->config->serverUrl, 'exception' => $lostBecause]);
        }
    }

    private function publishAtLeastOnce(MqttMessage $message): void
    {
        if ($this->outbound->isFull()) {
            $this->logger->warning('MQTT outbound buffer is full; dropping the oldest message', ['buffered' => $this->outbound->countPending()]);
        }

        $sequence = $this->outbound->enqueueMessage($message);

        if ($this->connection !== null) {
            $this->sendAtLeastOnce($this->connection, $sequence, $message);
        }
    }

    private function sendAtLeastOnce(MqttConnection $connection, int $sequence, MqttMessage $message): void
    {
        try {
            $connection->publishMessage($message)->map(fn() => $this->outbound->acknowledgeMessage($sequence))->ignore();
        } catch (MqttClientException) {
            // Still buffered; the next connection sends it again.
        }
    }

    private function subscribeOn(MqttConnection $connection, string $topicFilter): void
    {
        try {
            $connection->subscribeFilter($topicFilter)->map(function (?MqttQos $grantedQos) use ($topicFilter): void {
                if ($grantedQos === null) {
                    $this->logger->error('The MQTT server refused a subscription', ['filter' => $topicFilter]);
                }
            })->ignore();
        } catch (MqttClientException) {
            // Resubscribed on the next connection.
        }
    }

    private function waitBeforeReconnecting(int $attempt): void
    {
        try {
            $this->deadlines->delay($this->config->reconnectBackoff->delayFor($attempt), $this->stop->getCancellation());
        } catch (CancelledException) {
            // Closing; the loop sees the stop and ends.
        }
    }
}
