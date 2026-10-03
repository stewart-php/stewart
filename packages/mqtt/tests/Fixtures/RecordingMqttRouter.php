<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Tests\Fixtures;

use Amp\DeferredFuture;
use Amp\Future;
use Stewart\Contracts\Mqtt\Collection\MqttMessageCollection;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Runtime\Broker\Mqtt\MqttMessageRouter;

final class RecordingMqttRouter implements MqttMessageRouter
{
    public private(set) MqttMessageCollection $routed;

    /** @var DeferredFuture<MqttMessage>|null */
    private ?DeferredFuture $nextMessage = null;

    public function __construct()
    {
        $this->routed = MqttMessageCollection::empty();
    }

    public function routeMqttMessage(MqttMessage $message): void
    {
        $this->routed = $this->routed->withMqttMessage($message);
        $this->nextMessage?->complete($message);
        $this->nextMessage = null;
    }

    /** @return Future<MqttMessage> */
    public function waitForNextMessage(): Future
    {
        $this->nextMessage = new DeferredFuture();

        return $this->nextMessage->getFuture();
    }
}
