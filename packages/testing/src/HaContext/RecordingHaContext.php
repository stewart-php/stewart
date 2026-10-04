<?php

declare(strict_types=1);

namespace Stewart\Testing\HaContext;

use LogicException;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Connection\ConnectionEvent;
use Stewart\Contracts\Connection\ConnectionLost;
use Stewart\Contracts\Entity\Entity;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Event\EventTypeSelector;
use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exception\HistoryException;
use Stewart\Contracts\Exception\ServiceCallException;
use Stewart\Contracts\Exception\StateException;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\History\EntityStateHistory;
use Stewart\Contracts\History\HistoryQuery;
use Stewart\Contracts\Selector\Collection\SelectorCollection;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\Service\ServiceFields;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Contracts\Service\ServiceTargetSource;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\StateChangeStream;
use Stewart\Contracts\Stream\OperatorStream;
use Stewart\Contracts\Stream\StateChanges;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Topic\Collection\TopicEventCollection;
use Stewart\Contracts\Topic\TopicEvent;
use Stewart\Contracts\Topic\TopicPayload;
use Stewart\Testing\HaContext\Collection\RecordedHistoryQueryCollection;
use Stewart\Testing\HaContext\Collection\RecordedServiceCallCollection;
use Stewart\Testing\Stream\PushSource;
use Stewart\Testing\Time\ManualTimers;
use Stewart\Testing\Time\VirtualClock;

final class RecordingHaContext implements HaContext
{
    public private(set) RecordedServiceCallCollection $calls;

    public private(set) TopicEventCollection $published;

    public private(set) RecordedHistoryQueryCollection $historyQueries;

    private EntityStateCollection $states;

    private readonly SeededHistory $history;

    /** @var array<string, HistoryException> */
    private array $historyFailures = [];

    /** @var array<string, array<string, mixed>|ServiceCallException> */
    private array $serviceOutcomes = [];

    /** @var PushSource<StateChange> */
    private readonly PushSource $stateChanges;

    /** @var PushSource<HaEvent> */
    private readonly PushSource $events;

    /** @var PushSource<TopicEvent> */
    private readonly PushSource $topics;

    /** @var PushSource<ConnectionEvent> */
    private readonly PushSource $connectionEvents;

    public private(set) bool $connected = true;

    public readonly VirtualClock $clock;

    public function __construct(public readonly ManualTimers $timers = new ManualTimers())
    {
        $this->clock = $timers->clock;
        $this->calls = RecordedServiceCallCollection::empty();
        $this->published = TopicEventCollection::empty();
        $this->states = EntityStateCollection::empty();
        $this->historyQueries = RecordedHistoryQueryCollection::empty();
        $this->history = new SeededHistory();
        $this->stateChanges = new PushSource();
        $this->events = new PushSource();
        $this->topics = new PushSource();
        $this->connectionEvents = new PushSource();
    }

    /** @param array<string, mixed> $attributes */
    public function seedState(string $entityId, string $state, array $attributes = []): self
    {
        $seeded = new EntityState(new EntityId($entityId), $state, $attributes);
        $this->states = $this->states->withState($seeded);
        $this->history->recordState($seeded, $this->clock->getNow());

        return $this;
    }

    /** @param array<string, mixed> $attributes */
    public function seedHistoricalState(string $entityId, string $state, Instant $changedAt, array $attributes = []): self
    {
        $this->history->recordState(new EntityState(new EntityId($entityId), $state, $attributes, $changedAt, $changedAt), $changedAt);

        return $this;
    }

    public function stubHistoryFailure(string $entityId, HistoryException $failure): self
    {
        $this->historyFailures[new EntityId($entityId)->value] = $failure;

        return $this;
    }

    /** @param array<string, mixed> $response */
    public function stubServiceAnswer(string $domain, string $service, array $response): self
    {
        $this->serviceOutcomes[$domain . '.' . $service] = $response;

        return $this;
    }

    public function stubServiceFailure(string $domain, string $service, ServiceCallException $failure): self
    {
        $this->serviceOutcomes[$domain . '.' . $service] = $failure;

        return $this;
    }

    public function pushStateChange(StateChange $change): void
    {
        if ($change->to === null) {
            $this->states = $this->states->withoutState($change->entityId);
        } else {
            $this->states = $this->states->withState($change->to);
            $this->history->recordState($change->to, $this->clock->getNow());
        }

        $this->stateChanges->push($change);
    }

    /** @param array<string, mixed> $attributes */
    public function pushState(string $entityId, string $state, array $attributes = []): void
    {
        $this->pushStateChange(new StateChange(
            new EntityId($entityId),
            $this->states->find(new EntityId($entityId)),
            new EntityState(new EntityId($entityId), $state, $attributes),
        ));
    }

    public function pushEvent(HaEvent $event): void
    {
        $this->events->push($event);
    }

    public function pushConnection(ConnectionEvent $event): void
    {
        $this->connected = !$event instanceof ConnectionLost;
        $this->connectionEvents->push($event);
    }

    /** @throws LogicException */
    public function getLastCall(): RecordedServiceCall
    {
        return $this->calls->getLast() ?? throw new LogicException('No service has been called.');
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

    public function getHistory(EntityId|string $entityId, HistoryQuery $query): EntityStateHistory
    {
        $id = EntityId::fromStringOrId($entityId);
        $window = $query->resolveWindowAt($this->clock->getNow());
        $this->historyQueries = $this->historyQueries->withRecordedQuery(new RecordedHistoryQuery($id, $window, $query->includesAttributes));

        if (!$this->connected) {
            throw HistoryException::unreachable($id, 'Home Assistant is disconnected');
        }

        if (isset($this->historyFailures[$id->value])) {
            throw $this->historyFailures[$id->value];
        }

        return $this->history->sliceForWindow($id, $window, $query->includesAttributes);
    }

    public function listStates(string|EntityId|Selector|SelectorCollection|null $selector = null): EntityStateCollection
    {
        return $selector === null ? $this->states : $this->states->filterBySelector(Selector::fromSpec($selector));
    }

    public function watchStateChanges(string|EntityId|Selector|SelectorCollection $selector): StateChangeStream
    {
        $matcher = Selector::fromSpec($selector);

        return new StateChanges($this->stateChanges, $this->timers)
            ->filter(static fn(StateChange $change): bool => $matcher->matches($change->entityId->value));
    }

    public function watchEvents(string|Selector|SelectorCollection $eventType): EventStream
    {
        $matcher = EventTypeSelector::fromSpec($eventType)->selector;

        return new OperatorStream($this->events, $this->timers)
            ->filter(static fn(HaEvent $event): bool => $matcher->matches($event->type));
    }

    public function callService(string $domain, string $service, array $data = [], ?ServiceTargetSource $target = null): void
    {
        $this->callStubbedService($domain, $service, $data, $target, false);
    }

    public function callServiceForResponse(string $domain, string $service, array $data = [], ?ServiceTargetSource $target = null): ServiceResponse
    {
        return new ServiceResponse($domain, $service, $this->callStubbedService($domain, $service, $data, $target, true));
    }

    public function publish(string $topic, bool|int|float|string|array|null $payload = null): void
    {
        $event = new TopicEvent($topic, new TopicPayload($payload)->value, new AppId('test'), $this->clock->getNow());

        $this->published = $this->published->withTopicEvent($event);
        $this->topics->push($event);
    }

    public function watchTopic(string|Selector|SelectorCollection $topic): EventStream
    {
        $matcher = Selector::fromSpec($topic);

        return new OperatorStream($this->topics, $this->timers)
            ->filter(static fn(TopicEvent $event): bool => $matcher->matches($event->topic));
    }

    public function watchConnection(): EventStream
    {
        return new OperatorStream($this->connectionEvents, $this->timers);
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @throws ServiceCallException
     */
    private function callStubbedService(string $domain, string $service, array $data, ?ServiceTargetSource $target, bool $returnsResponse): array
    {
        $call = new RecordedServiceCall(
            $domain,
            $service,
            ServiceFields::fromFieldsDroppingNulls($data)->fields,
            $target?->toServiceTarget(),
            $returnsResponse,
        );

        if (!$this->connected) {
            throw ServiceCallException::unreachable($domain, $service, 'Home Assistant is disconnected');
        }

        $this->calls = $this->calls->withRecordedCall($call);
        $outcome = $this->serviceOutcomes[$call->getServiceName()] ?? [];

        return $outcome instanceof ServiceCallException ? throw $outcome : $outcome;
    }
}
