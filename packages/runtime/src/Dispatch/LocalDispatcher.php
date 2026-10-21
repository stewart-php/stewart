<?php

declare(strict_types=1);

namespace Stewart\Runtime\Dispatch;

use Closure;
use LogicException;
use Stewart\Contracts\Connection\ConnectionEvent;
use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\Exposure\Command\ExposedCommand;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Registry\EntityFilter;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Subscription;
use Stewart\Contracts\Topic\TopicEvent;
use Stewart\Contracts\Trigger\TriggerEvent;
use Stewart\Runtime\Ipc\WorkerSettings;
use Stewart\Runtime\Model\Collection\SubscriptionIdCollection;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Model\SubscriptionKind;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Registry\RegistryCache;
use Stewart\Runtime\Scope\ScopeLifecycle;
use Stewart\Support\State\Collection\StateChangeCollection;
use Stewart\Support\Stream\SubscriptionScope;
use Stewart\Support\Wire\TriggerSpec;
use Throwable;

final class LocalDispatcher
{
    /** @var array<string, SubscriptionQueue> */
    private array $queues = [];

    private int $counter = 0;

    private readonly string $subscriptionIdPrefix;

    private readonly int $subscriptionQueueLimit;

    private readonly SelectorIndex $stateIndex;

    private readonly EntityFilterIndex $stateFilterIndex;

    public function __construct(
        WorkerId $workerId,
        WorkerSettings $workerSettings,
        private readonly DispatchListener $listener,
        private readonly SubscriptionListener $subscriptions,
        private readonly ScopeLifecycle $scopes,
        RegistryCache $registry,
    ) {
        $this->subscriptionIdPrefix = $workerId->toProcessIdPrefix();
        $this->subscriptionQueueLimit = $workerSettings->subscriptionBuffer;
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

        $scopeState = $this->scopes->findOrCreateStateOf($scope);

        if ($scopeState->isClosed()) {
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

        $queue = new SubscriptionQueue($subscription, $this->subscriptionQueueLimit, $this->listener, $scopeState);

        $this->queues[$subscription->id->value] = $queue;
        $subscriptionScope->deliverVia($queue->emit(...));
        $subscriptionScope->reportHandlerCallsTo($queue->recordHandlerCall(...));

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

    public function startDrainingQueuesOf(ResourceScope $scope): void
    {
        foreach ($this->listQueuesOf($scope) as $queue) {
            $queue->startDrainingIfPending();
        }
    }

    public function discardPendingWorkOf(ResourceScope $scope): void
    {
        foreach ($this->listQueuesOf($scope) as $queue) {
            $queue->discardPendingWork();
        }
    }

    public function dispatchStateChange(StateChange $change): void
    {
        $this->dispatchStateChanges(StateChangeCollection::fromList([$change]));
    }

    public function dispatchStateChanges(StateChangeCollection $changes): void
    {
        $changesByQueue = [];

        foreach ($changes as $change) {
            foreach ($this->listStateChangeQueuesMatching($change) as $subscriptionId => $queue) {
                $changesByQueue[$subscriptionId][] = $change;
            }
        }

        foreach ($changesByQueue as $subscriptionId => $queueChanges) {
            $this->queues[$subscriptionId]->pushBurst($queueChanges);
        }
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

    /** @param Closure(QueueDiscardReason): void $onDiscarded */
    public function dispatchExposedCommand(ResourceScope $scope, ExposedEntityKey $key, ExposedCommand $command, Closure $onDiscarded): bool
    {
        $queues = array_filter(
            $this->listQueuesOf($scope),
            static fn(SubscriptionQueue $queue): bool => $queue->subscription->kind === SubscriptionKind::ExposedCommand && $queue->subscription->selector->matches($key->value),
        );
        $undiscarded = \count($queues);

        // Another subscription may still handle the command, so only the last discard reports it.
        $discardOne = static function (QueueDiscardReason $reason) use (&$undiscarded, $onDiscarded): void {
            if (--$undiscarded === 0) {
                $onDiscarded($reason);
            }
        };

        foreach ($queues as $queue) {
            $queue->push($command, $discardOne);
        }

        return $queues !== [];
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
    private function listStateChangeQueuesMatching(StateChange $change): array
    {
        $matching = [];
        $deliverTo = [
            ...$this->stateIndex->findMatching(SubscriptionKind::StateChange, $change->entityId->value),
            ...$this->stateFilterIndex->findMatching($change->entityId),
        ];

        foreach ($deliverTo as $id) {
            $queue = $this->queues[$id->value] ?? null;

            if ($queue !== null && $queue->subscription->kind === SubscriptionKind::StateChange) {
                $matching[$id->value] = $queue;
            }
        }

        return $matching;
    }

    /** @return array<string, SubscriptionQueue> */
    private function listQueuesOf(ResourceScope $scope): array
    {
        return array_filter($this->queues, static fn(SubscriptionQueue $queue): bool => $queue->subscription->scope->equals($scope));
    }
}
