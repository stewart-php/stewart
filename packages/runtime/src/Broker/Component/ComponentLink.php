<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Component;

use Psr\Log\LoggerInterface;
use Stewart\Client\Component\ComponentProtocol;
use Stewart\Client\Component\ComponentSessionEvent;
use Stewart\Client\Component\ComponentSessionRequest;
use Stewart\Client\Component\ComponentVersion;
use Stewart\Client\Component\SessionReplaced;
use Stewart\Client\Exception\HaClientException;
use Stewart\Client\HaClient;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Config\ExposeConfig;

// Session subscriptions live as long as one connection, so every connect detects the component again.
final class ComponentLink
{
    private const int COMMAND_TIMEOUT_SECONDS = 5;

    private ComponentDetection $detection;

    private int $connectionGeneration = 0;

    public function __construct(
        private readonly HaClient $client,
        private readonly LoggerInterface $logger,
        private readonly Clock $clock,
        private readonly ExposeConfig $expose,
        private readonly string $componentSessionStewartVersion,
    ) {
        $this->detection = new ComponentDetection(ComponentState::Unchecked, null, $clock->getNow());
    }

    public function describeDetection(): ComponentDetection
    {
        return $this->detection;
    }

    /** @throws HaClientException */
    public function establishLink(): void
    {
        $generation = ++$this->connectionGeneration;
        $version = null;

        try {
            $version = $this->client->findComponentVersion();

            if ($version === null) {
                $this->logger->info('The stewart Home Assistant integration is not installed, so apps cannot expose entities');
                $this->recordState(ComponentState::Missing, null);

                return;
            }

            if (!$version->isCompatible()) {
                $this->logger->warning('The stewart Home Assistant integration speaks another protocol; update it or Stewart so they match', [
                    'component_version' => $version->componentVersion,
                    'component_protocol' => $version->protocol,
                    'stewart_protocol' => ComponentProtocol::VERSION,
                ]);
                $this->recordState(ComponentState::ProtocolMismatch, $version);

                return;
            }

            $this->client->subscribeComponentSession(
                new ComponentSessionRequest($this->expose->instance, $this->componentSessionStewartVersion, Duration::seconds(self::COMMAND_TIMEOUT_SECONDS)),
                fn(ComponentSessionEvent $event) => $this->handleSessionEvent($event, $generation),
            );
        } catch (HaClientException $e) {
            if (!$e->reason->isCommandRefusal()) {
                throw $e;
            }

            $this->logger->warning('Home Assistant refused the stewart integration session', ['exception' => $e]);
            $this->recordState(ComponentState::Refused, $version);

            return;
        }

        $this->logger->info('Opened the stewart Home Assistant integration session', [
            'component_version' => $version->componentVersion,
            'instance' => (string) $this->expose->instance,
        ]);
        $this->recordState(ComponentState::Active, $version);
    }

    public function markLinkLost(): void
    {
        ++$this->connectionGeneration;
        $this->recordState(ComponentState::Unchecked, $this->detection->version);
    }

    private function handleSessionEvent(ComponentSessionEvent $event, int $generation): void
    {
        if ($generation !== $this->connectionGeneration || !$event instanceof SessionReplaced) {
            return;
        }

        $this->logger->warning('Another Stewart took over the stewart integration session; give each daemon its own expose.instance', [
            'instance' => (string) $this->expose->instance,
        ]);
        $this->recordState(ComponentState::Replaced, $this->detection->version);
    }

    private function recordState(ComponentState $state, ?ComponentVersion $version): void
    {
        $since = $state === $this->detection->state ? $this->detection->since : $this->clock->getNow();
        $this->detection = new ComponentDetection($state, $version, $since);
    }
}
