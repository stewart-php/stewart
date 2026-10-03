<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broker\Mqtt;

use Amp\DeferredFuture;
use Amp\Future;
use LogicException;
use Stewart\Contracts\Mqtt\Collection\MqttMessageCollection;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Runtime\Broker\Mqtt\MqttLink;
use Stewart\Runtime\Broker\Mqtt\MqttMessageRouter;

final class RecordingMqttLink implements MqttLink
{
    /** @var list<string> */
    public array $subscribedFilters = [];

    /** @var list<string> */
    public array $unsubscribedFilters = [];

    public private(set) MqttMessageCollection $published;

    public bool $closed = false;

    private ?MqttMessageRouter $router = null;

    /** @var DeferredFuture<string>|null */
    private ?DeferredFuture $nextSubscribe = null;

    /** @var DeferredFuture<MqttMessage>|null */
    private ?DeferredFuture $nextPublish = null;

    public function __construct()
    {
        $this->published = MqttMessageCollection::empty();
    }

    public function startInBackground(MqttMessageRouter $router): void
    {
        $this->router = $router;
    }

    public function publishMessage(MqttMessage $message): void
    {
        $this->published = $this->published->withMqttMessage($message);
        $this->nextPublish?->complete($message);
        $this->nextPublish = null;
    }

    public function subscribeFilter(string $topicFilter): void
    {
        $this->subscribedFilters[] = $topicFilter;
        $this->nextSubscribe?->complete($topicFilter);
        $this->nextSubscribe = null;
    }

    public function unsubscribeFilter(string $topicFilter): void
    {
        $this->unsubscribedFilters[] = $topicFilter;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    /** @return Future<string> */
    public function waitForNextSubscribe(): Future
    {
        $this->nextSubscribe = new DeferredFuture();

        return $this->nextSubscribe->getFuture();
    }

    /** @return Future<MqttMessage> */
    public function waitForNextPublish(): Future
    {
        $this->nextPublish = new DeferredFuture();

        return $this->nextPublish->getFuture();
    }

    public function receiveFromServer(MqttMessage $message): void
    {
        ($this->router ?? throw new LogicException('The link was never started.'))->routeMqttMessage($message);
    }
}
