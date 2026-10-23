<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Stewart\Contracts\Action\ActionName;
use Stewart\Contracts\Action\ActionResponse;
use Stewart\Contracts\Action\ActionTargetSource;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\Entity;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exception\StateException;
use Stewart\Contracts\Exception\TopicError;
use Stewart\Contracts\Exception\TopicException;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\History\EntityStateHistory;
use Stewart\Contracts\History\HistoryQuery;
use Stewart\Contracts\Registry\EntityFilter;
use Stewart\Contracts\Registry\Registry;
use Stewart\Contracts\Selector\Collection\SelectorCollection;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\EventContext;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\StateChangeStream;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Trigger\Collection\HaTriggerCollection;
use Stewart\Contracts\Trigger\HaTrigger;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Registry\RegistryCache;
use Stewart\Runtime\State\StateCache;
use Stewart\Runtime\Worker\Context\ActionCaller;
use Stewart\Runtime\Worker\Context\DispatchStreams;
use Stewart\Runtime\Worker\Context\EventFirer;
use Stewart\Runtime\Worker\Context\HistoryReader;
use Stewart\Runtime\Worker\Context\TopicPublisher;
use Stewart\Support\State\StewartIdentity;
use Stewart\Support\Wire\ActionFields;
use Stewart\Support\Wire\EventPayload;
use Stewart\Support\Wire\EventTypeSelector;
use Stewart\Support\Wire\TopicPayload;
use Stewart\Support\Wire\TriggerSpec;

final readonly class WorkerHaContext implements HaContext
{
    public function __construct(
        private StateCache $states,
        private RegistryCache $registry,
        private ConnectionStatus $connection,
        private ActionCaller $actions,
        private HistoryReader $history,
        private EventFirer $eventFires,
        private DispatchStreams $streams,
        private TopicPublisher $topics,
        private StewartIdentity $identity,
        private Clock $clock,
        private ResourceScope $resourceScope,
    ) {}

    public function forApp(AppId $appId): self
    {
        return new self($this->states, $this->registry, $this->connection, $this->actions, $this->history, $this->eventFires, $this->streams, $this->topics, $this->identity, $this->clock, ResourceScope::forApp($appId));
    }

    public function getState(EntityId|string $entityId): ?EntityState
    {
        return $this->states->find(EntityId::fromStringOrId($entityId));
    }

    public function getEntity(EntityId|string $entityId): Entity
    {
        return new Entity($this, EntityId::fromStringOrId($entityId));
    }

    public function requireState(EntityId|string $entityId): EntityState
    {
        $id = EntityId::fromStringOrId($entityId);

        return $this->states->find($id) ?? throw StateException::entityNotFound($id);
    }

    public function getHeldStateDuration(EntityId|string $entityId): ?Duration
    {
        $changedAt = $this->getState($entityId)?->lastChangedAt;

        return $changedAt === null ? null : $this->clock->getNow()->elapsedSince($changedAt);
    }

    public function hasHeldStateFor(EntityId|string $entityId, Duration $duration): bool
    {
        $held = $this->getHeldStateDuration($entityId);

        return $held !== null && !$duration->isLongerThan($held);
    }

    public function getHistory(EntityId|string $entityId, HistoryQuery $query): EntityStateHistory
    {
        return $this->history->fetchHistory($this->resourceScope, EntityId::fromStringOrId($entityId), $query);
    }

    public function listStates(string|EntityId|Selector|SelectorCollection|EntityFilter|null $selector = null): EntityStateCollection
    {
        if ($selector instanceof EntityFilter) {
            return $this->states->getAllStates()->filterByEntityFilter($selector, $this->registry);
        }

        return $this->states->filterBySelector($selector === null ? null : Selector::fromSpec($selector));
    }

    public function watchStateChanges(string|EntityId|Selector|SelectorCollection|EntityFilter $selector): StateChangeStream
    {
        return $this->streams->watchStateChanges($this->resourceScope, $selector instanceof EntityFilter ? $selector : Selector::fromSpec($selector));
    }

    public function waitForState(EntityId|string $entityId, string $state, ?Duration $timeout = null): ?EntityState
    {
        return $this->getEntity($entityId)->waitForState($state, $timeout);
    }

    public function getRegistry(): Registry
    {
        return $this->registry;
    }

    public function watchEvents(string|Selector|SelectorCollection $eventType): EventStream
    {
        return $this->streams->watchEvents($this->resourceScope, EventTypeSelector::fromSpec($eventType));
    }

    public function watchTrigger(HaTrigger|HaTriggerCollection|array $trigger, array $variables = []): EventStream
    {
        return $this->streams->watchTrigger($this->resourceScope, TriggerSpec::fromSpec($trigger, $variables));
    }

    public function callAction(string $action, array $data = [], ?ActionTargetSource $target = null): EventContext
    {
        return $this->actions->callAction($this->resourceScope, new ActionName($action), ActionFields::fromFieldsDroppingNulls($data), $target?->toActionTarget());
    }

    public function callActionForResponse(string $action, array $data = [], ?ActionTargetSource $target = null): ActionResponse
    {
        return $this->actions->callActionForResponse($this->resourceScope, new ActionName($action), ActionFields::fromFieldsDroppingNulls($data), $target?->toActionTarget());
    }

    public function fireEvent(string $eventType, array $data = []): EventContext
    {
        return $this->eventFires->fireEvent($this->resourceScope, new EventPayload($eventType, $data));
    }

    public function wasCausedByStewart(StateChange|EntityState|HaEvent $subject): bool
    {
        return $this->identity->wasCausedByStewart($subject);
    }

    public function publish(string $topic, bool|int|float|string|array|null $payload = null): void
    {
        $topicPayload = new TopicPayload($payload);

        try {
            $this->topics->publish($this->resourceScope, $topic, $topicPayload);
        } catch (TransportException $e) {
            throw TopicException::createForReason(TopicError::PublishFailed, $e, topic: $topic);
        }
    }

    public function watchTopic(string|Selector|SelectorCollection $topic): EventStream
    {
        return $this->streams->watchTopic($this->resourceScope, Selector::fromSpec($topic));
    }

    public function watchConnection(): EventStream
    {
        return $this->streams->watchConnection($this->resourceScope);
    }

    public function isConnected(): bool
    {
        return $this->connection->connected;
    }
}
