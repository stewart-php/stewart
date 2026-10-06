<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Context;

use Stewart\Contracts\Connection\ConnectionEvent;
use Stewart\Contracts\Event\EventTypeSelector;
use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\EventStream;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Registry\EntityFilter;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\StateChangeStream;
use Stewart\Contracts\Stream\OperatorStream;
use Stewart\Contracts\Stream\StateChanges;
use Stewart\Contracts\Time\Timers;
use Stewart\Contracts\Topic\TopicEvent;
use Stewart\Contracts\Trigger\TriggerEvent;
use Stewart\Contracts\Trigger\TriggerSpec;
use Stewart\Runtime\Dispatch\DispatchSource;
use Stewart\Runtime\Dispatch\LocalDispatcher;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\SubscriptionKind;
use Stewart\Support\Time\Deadlines;

final readonly class DispatchStreams
{
    public function __construct(
        private LocalDispatcher $dispatcher,
        private Timers&Deadlines $timers,
    ) {}

    public function watchStateChanges(ResourceScope $scope, Selector|EntityFilter $match): StateChangeStream
    {
        /** @var DispatchSource<StateChange> $source */
        $source = $match instanceof EntityFilter
            ? new DispatchSource($this->dispatcher, $scope, SubscriptionKind::StateChange, Selector::any(), entityFilter: $match)
            : new DispatchSource($this->dispatcher, $scope, SubscriptionKind::StateChange, $match);

        return new StateChanges($source, $this->timers);
    }

    /** @return EventStream<HaEvent> */
    public function watchEvents(ResourceScope $scope, EventTypeSelector $eventType): EventStream
    {
        /** @var DispatchSource<HaEvent> $source */
        $source = new DispatchSource($this->dispatcher, $scope, SubscriptionKind::Event, $eventType->selector);

        return new OperatorStream($source, $this->timers);
    }

    /** @return EventStream<TopicEvent> */
    public function watchTopic(ResourceScope $scope, Selector $selector): EventStream
    {
        /** @var DispatchSource<TopicEvent> $source */
        $source = new DispatchSource($this->dispatcher, $scope, SubscriptionKind::Topic, $selector);

        return new OperatorStream($source, $this->timers);
    }

    /** @return EventStream<MqttMessage> */
    public function watchMqttMessages(ResourceScope $scope, Selector $topicFilter): EventStream
    {
        /** @var DispatchSource<MqttMessage> $source */
        $source = new DispatchSource($this->dispatcher, $scope, SubscriptionKind::Mqtt, $topicFilter);

        return new OperatorStream($source, $this->timers);
    }

    /** @return EventStream<TriggerEvent> */
    public function watchTrigger(ResourceScope $scope, TriggerSpec $trigger): EventStream
    {
        /** @var DispatchSource<TriggerEvent> $source */
        $source = new DispatchSource($this->dispatcher, $scope, SubscriptionKind::Trigger, Selector::exact($trigger->getSharingKey()), $trigger);

        return new OperatorStream($source, $this->timers);
    }

    /** @return EventStream<ConnectionEvent> */
    public function watchConnection(ResourceScope $scope): EventStream
    {
        /** @var DispatchSource<ConnectionEvent> $source */
        $source = new DispatchSource($this->dispatcher, $scope, SubscriptionKind::Connection, Selector::any());

        return new OperatorStream($source, $this->timers);
    }
}
