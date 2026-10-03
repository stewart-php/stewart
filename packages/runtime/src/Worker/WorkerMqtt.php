<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exception\MqttException;
use Stewart\Contracts\Mqtt\Mqtt;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Mqtt\MqttQos;
use Stewart\Contracts\Selector\Selector;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Message\MqttPublish;
use Stewart\Runtime\Ipc\Transport;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Worker\Context\DispatchStreams;

final readonly class WorkerMqtt implements Mqtt
{
    public function __construct(
        private Transport $transport,
        private DispatchStreams $streams,
        private AppActivityCounters $activityCounters,
        private bool $brokerMqttEnabled,
        private ResourceScope $resourceScope,
    ) {}

    /** @throws MqttException */
    public function forApp(AppId $appId): self
    {
        $this->assertBrokerMqttEnabled();

        return new self($this->transport, $this->streams, $this->activityCounters, $this->brokerMqttEnabled, ResourceScope::forApp($appId));
    }

    public function publish(string $topic, string|array $payload, MqttQos $qos = MqttQos::AtMostOnce, bool $retain = false): void
    {
        $this->assertBrokerMqttEnabled();
        $message = MqttMessage::createForPublish($topic, $payload, $qos, $retain);

        try {
            $this->transport->send(new MqttPublish($message, $this->resourceScope));
        } catch (TransportException $e) {
            throw MqttException::publishFailed($topic, $e);
        }

        $this->activityCounters->findOrCreateActivityForScope($this->resourceScope)->recordPublish();
    }

    public function watchMessages(string $topicFilter): EventStream
    {
        $this->assertBrokerMqttEnabled();

        return $this->streams->watchMqttMessages($this->resourceScope, Selector::mqttFilter($topicFilter));
    }

    /** @throws MqttException */
    private function assertBrokerMqttEnabled(): void
    {
        if (!$this->brokerMqttEnabled) {
            throw MqttException::notConfigured();
        }
    }
}
