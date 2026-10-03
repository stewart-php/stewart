<?php

declare(strict_types=1);

namespace Stewart\Testing\Mqtt;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Mqtt\Collection\MqttMessageCollection;
use Stewart\Contracts\Mqtt\Mqtt;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Mqtt\MqttQos;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\Stream\OperatorStream;
use Stewart\Testing\Stream\PushSource;
use Stewart\Testing\Time\ManualTimers;

final class RecordingMqtt implements Mqtt
{
    public private(set) MqttMessageCollection $published;

    /** @var PushSource<MqttMessage> */
    private readonly PushSource $incoming;

    public function __construct(public readonly ManualTimers $timers = new ManualTimers())
    {
        $this->published = MqttMessageCollection::empty();
        $this->incoming = new PushSource();
    }

    public function pushMessage(MqttMessage $message): void
    {
        $this->incoming->push($message);
    }

    public function publish(string $topic, string|array $payload, MqttQos $qos = MqttQos::AtMostOnce, bool $retain = false): void
    {
        $this->published = $this->published->withMqttMessage(MqttMessage::createForPublish($topic, $payload, $qos, $retain));
    }

    public function watchMessages(string $topicFilter): EventStream
    {
        $filter = Selector::mqttFilter($topicFilter);

        return new OperatorStream($this->incoming, $this->timers)
            ->filter(static fn(MqttMessage $message): bool => $filter->matches($message->topic));
    }
}
