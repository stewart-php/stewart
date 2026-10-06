<?php

declare(strict_types=1);

namespace Stewart\Runtime\Dispatch;

use Closure;
use LogicException;
use Stewart\Contracts\Connection\ConnectionEvent;
use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Registry\EntityFilter;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Stream\SubscriptionScope;
use Stewart\Contracts\Subscription;
use Stewart\Contracts\Topic\TopicEvent;
use Stewart\Contracts\Trigger\TriggerEvent;
use Stewart\Contracts\Trigger\TriggerSpec;
use Stewart\Runtime\Model\Collection\SubscriptionIdCollection;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Model\SubscriptionKind;
use Stewart\Runtime\Registry\RegistryCache;
use Stewart\Runtime\Scope\ScopeLifecycle;
use Throwable;

final class LocalDispatcher
{
    /** @var array<string, SubscriptionQueue> */
    private array $queues = [];

    private int $counter = 0;

    private readonly SelectorIndex $stateIndex;

    private readonly EntityFilterIndex $stateFilterIndex;

    public function __construct(
        private readonly string $subscriptionIdPrefix,
        private readonly int $subscriptionQueueLimit,
        private readonly DispatchListener $listener,
        private readonly SubscriptionListener $subscriptions,
        private readonly ScopeLifecycle $scopes,
        RegistryCache $registry,
    ) {
        $this->stateIndex = new SelectorIndex();
        $this->stateFilterIndex = new EntityFilterIndex($registry);
    }

    public function register(
        ResourceScope $scope,
        SubscriptionKind $kind,
        Selector $selector,
        SubscriptionScope $subscriptionScope,
        Closure $handler,
        ?TriggerSpec $trigger = null,
        ?EntityFilter $entityFilter = null,
    ): Subscription {
        if ($kind->needsTriggerSpec() !== ($trigger !== null)) {
            throw new LogicException(\sprintf('A %s subscription %s a trigger spec.', $kind->value, $trigger === null ? 'needs' : 'takes no'));
        }

        if ($entityFilter !== null && $kind !== SubscriptionKind::StateChange) {
            throw new LogicException(\sprintf('A %s subscription takes no entity filter.', $kind->value));
        }

        $id = SubscriptionId::fromString($this->subscriptionIdPrefix . ':' . $this->counter++);

        if ($this->scopes->isClosed($scope)) {
            return $this->refusedSubscription($id, $subscriptionScope);
        }

        $subscription = new RegisteredSubscription(
            $id,
            $scope,
            $kind,
            $selector,
            $subscriptionScope,
            $handler,
            $trigger,
            $entityFilter,
        );

        $queue = new SubscriptionQueue(
            $subscription,
            $this->subscriptionQueueLimit,
            $this->listener,
            $this->scopes->isLive($scope),
            $this->scopes->isPaused($scope),
        );

        $this->queues[$subscription->id->value] = $queue;
        $subscriptionScope->deliverVia($queue->emit(...));

        if ($entityFilter !== null) {
            $this->stateFilterIndex->add($subscription->id, $entityFilter);
        } elseif ($kind === SubscriptionKind::StateChange) {
            $this->stateIndex->add($subscription->id, $kind, $selector);
        }

        try {
            $this->subscriptions->subscriptionRegistered($subscription);
        } catch (Throwable $e) {
            unset($this->queues[$subscription->id->value]);
            $this->stateIndex->remove($subscription->id);
            $this->stateFilterIndex->remove($subscription->id);
            $queue->cancel();

            throw $e;
        }

        return new LocalSubscription($subscription->id, $this->cancel(...));
    }

    public function activateQueuesOf(ResourceScope $scope): void
    {
        foreach ($this->listQueuesOf($scope) as $queue) {
            $queue->activate();
        }
    }

    public function pauseQueuesOf(ResourceScope $scope): void
    {
        foreach ($this->listQueuesOf($scope) as $queue) {
            $queue->pause();
        }
    }

    public function resumeQueuesOf(ResourceScope $scope): void
    {
        foreach ($this->listQueuesOf($scope) as $queue) {
            $queue->resume();
        }
    }

    public function dispatchStateChange(StateChange $change): void
    {
        $this->deliver(SubscriptionKind::StateChange, $change, $this->stateIndex->findMatching(SubscriptionKind::StateChange, $change->entityId->value));
        $this->deliver(SubscriptionKind::StateChange, $change, $this->stateFilterIndex->findMatching($change->entityId));
    }

    public function dispatchEvent(HaEvent $event, SubscriptionIdCollection $deliverTo): void
    {
        $this->deliver(SubscriptionKind::Event, $event, $deliverTo);
    }

    public function dispatchTopic(TopicEvent $event, SubscriptionIdCollection $deliverTo): void
    {
        $this->deliver(SubscriptionKind::Topic, $event, $deliverTo);
    }

    public function dispatchMqttMessage(MqttMessage $message, SubscriptionIdCollection $deliverTo): void
    {
        $this->deliver(SubscriptionKind::Mqtt, $message, $deliverTo);
    }

    public function dispatchTrigger(TriggerEvent $event, SubscriptionIdCollection $deliverTo): void
    {
        $this->deliver(SubscriptionKind::Trigger, $event, $deliverTo);
    }

    public function dispatchConnection(ConnectionEvent $event): void
    {
        foreach ($this->queues as $queue) {
            if ($queue->subscription->kind === SubscriptionKind::Connection) {
                $queue->push($event);
            }
        }
    }

    public function cancel(SubscriptionId $subscriptionId): void
    {
        $queue = $this->queues[$subscriptionId->value] ?? null;

        if ($queue === null) {
            return;
        }

        unset($this->queues[$subscriptionId->value]);
        $this->stateIndex->remove($subscriptionId);
        $this->stateFilterIndex->remove($subscriptionId);
        $queue->cancel();

        $this->subscriptions->subscriptionCancelled($queue->subscription);
    }

    public function cancelAll(): void
    {
        foreach ($this->queues as $queue) {
            $this->cancel($queue->subscription->id);
        }
    }

    public function cancelSubscriptionsOf(ResourceScope $scope): void
    {
        foreach ($this->listQueuesOf($scope) as $queue) {
            $this->cancel($queue->subscription->id);
        }
    }

    public function countFor(ResourceScope $scope): int
    {
        return \count($this->listQueuesOf($scope));
    }

    private function refusedSubscription(SubscriptionId $id, SubscriptionScope $subscriptionScope): Subscription
    {
        $subscriptionScope->close();

        $subscription = new LocalSubscription($id, static function (): void {});
        $subscription->unsubscribe();

        return $subscription;
    }

    private function deliver(SubscriptionKind $kind, object $event, SubscriptionIdCollection $deliverTo): void
    {
        foreach ($deliverTo as $id) {
            $queue = $this->queues[$id->value] ?? null;

            if ($queue !== null && $queue->subscription->kind === $kind) {
                $queue->push($event);
            }
        }
    }

    /** @return array<string, SubscriptionQueue> */
    private function listQueuesOf(ResourceScope $scope): array
    {
        return array_filter($this->queues, static fn(SubscriptionQueue $queue): bool => $queue->subscription->scope->equals($scope));
    }
}
