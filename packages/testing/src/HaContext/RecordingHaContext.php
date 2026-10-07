<?php

declare(strict_types=1);

namespace Stewart\Testing\HaContext;

use LogicException;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Connection\ConnectionEvent;
use Stewart\Contracts\Connection\ConnectionLost;
use Stewart\Contracts\Entity\Entity;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Event\EventOrigin;
use Stewart\Contracts\Event\EventPayload;
use Stewart\Contracts\Event\EventTypeSelector;
use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exception\EventFireException;
use Stewart\Contracts\Exception\HistoryException;
use Stewart\Contracts\Exception\ServiceCallException;
use Stewart\Contracts\Exception\StateException;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\History\EntityStateHistory;
use Stewart\Contracts\History\HistoryQuery;
use Stewart\Contracts\Registry\EntityFilter;
use Stewart\Contracts\Registry\Registry;
use Stewart\Contracts\Selector\Collection\SelectorCollection;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\Service\ServiceFields;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Contracts\Service\ServiceTargetSource;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\EventContext;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\StateChangeStream;
use Stewart\Contracts\Stream\OperatorStream;
use Stewart\Contracts\Stream\StateChanges;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Topic\Collection\TopicEventCollection;
use Stewart\Contracts\Topic\TopicEvent;
use Stewart\Contracts\Topic\TopicPayload;
use Stewart\Contracts\Trigger\Collection\HaTriggerCollection;
use Stewart\Contracts\Trigger\Collection\TriggerSpecCollection;
use Stewart\Contracts\Trigger\HaTrigger;
use Stewart\Contracts\Trigger\TriggerEvent;
use Stewart\Contracts\Trigger\TriggerSpec;
use Stewart\Testing\HaContext\Collection\RecordedEventFireCollection;
use Stewart\Testing\HaContext\Collection\RecordedHistoryQueryCollection;
use Stewart\Testing\HaContext\Collection\RecordedServiceCallCollection;
use Stewart\Testing\Registry\InMemoryRegistry;
use Stewart\Testing\Stream\PushSource;
use Stewart\Testing\Time\ManualTimers;
use Stewart\Testing\Time\VirtualClock;

final class RecordingHaContext implements HaContext
{
    public const string STEWART_USER_ID = 'stewart-user';

    private const string CONTEXT_ID_PREFIX = 'recorded-';

    public private(set) RecordedServiceCallCollection $calls;

    public private(set) TopicEventCollection $published;

    public private(set) RecordedEventFireCollection $firedEvents;

    public private(set) RecordedHistoryQueryCollection $historyQueries;

    private EntityStateCollection $states;

    private readonly SeededHistory $history;

    /** @var array<string, HistoryException> */
    private array $historyFailures = [];

    /** @var array<string, array<string, mixed>|ServiceCallException> */
    private array $serviceOutcomes = [];

    /** @var array<string, EventFireException> */
    private array $eventFireFailures = [];

    private int $issuedContextCount = 0;

    /** @var PushSource<StateChange> */
    private readonly PushSource $stateChanges;

    /** @var PushSource<HaEvent> */
    private readonly PushSource $events;

    /** @var PushSource<TopicEvent> */
    private readonly PushSource $topics;

    /** @var PushSource<ConnectionEvent> */
    private readonly PushSource $connectionEvents;

    /** @var PushSource<PushedTrigger> */
    private readonly PushSource $triggers;

    private TriggerSpecCollection $watchedTriggers;

    public private(set) bool $connected = true;

    public readonly VirtualClock $clock;

    public readonly InMemoryRegistry $registry;

    public function __construct(public readonly ManualTimers $timers = new ManualTimers())
    {
        $this->clock = $timers->clock;
        $this->registry = new InMemoryRegistry();
        $this->calls = RecordedServiceCallCollection::empty();
        $this->published = TopicEventCollection::empty();
        $this->firedEvents = RecordedEventFireCollection::empty();
        $this->states = EntityStateCollection::empty();
        $this->historyQueries = RecordedHistoryQueryCollection::empty();
        $this->history = new SeededHistory();
        $this->stateChanges = new PushSource();
        $this->events = new PushSource();
        $this->topics = new PushSource();
        $this->connectionEvents = new PushSource();
        $this->triggers = new PushSource();
        $this->watchedTriggers = TriggerSpecCollection::empty();
    }

    /** @param array<string, mixed> $attributes */
    public function seedState(string $entityId, string $state, array $attributes = [], ?Instant $changedAt = null): self
    {
        $changedAt ??= $this->clock->getNow();
        $seeded = new EntityState(new EntityId($entityId), $state, $attributes, $changedAt, $changedAt);
        $this->states = $this->states->withState($seeded);
        $this->history->recordState($seeded, $changedAt);

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

    public function stubEventFireFailure(string $eventType, EventFireException $failure): self
    {
        $this->eventFireFailures[$eventType] = $failure;

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
    public function pushState(string $entityId, string $state, array $attributes = [], ?EventContext $context = null): void
    {
        $id = new EntityId($entityId);
        $previous = $this->states->find($id);
        $now = $this->clock->getNow();
        $changedAt = $previous?->state === $state ? $previous->lastChangedAt ?? $now : $now;

        $this->pushStateChange(new StateChange(
            $id,
            $previous,
            new EntityState($id, $state, $attributes, $changedAt, $now, $context),
            context: $context,
        ));
    }

    public function pushEvent(HaEvent $event): void
    {
        $this->events->push($event);
    }

    public function pushTrigger(HaTrigger|HaTriggerCollection $trigger, TriggerEvent $event): void
    {
        $this->triggers->push(new PushedTrigger(TriggerSpec::fromSpec($trigger), $event));
    }

    public function listWatchedTriggers(): TriggerSpecCollection
    {
        return $this->watchedTriggers;
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
        $this->historyQueries = $this->historyQueries->withRecordedQuery(new RecordedHistoryQuery($id, $window, $query->detail));

        if (!$this->connected) {
            throw HistoryException::unreachable($id, 'Home Assistant is disconnected');
        }

        if (isset($this->historyFailures[$id->value])) {
            throw $this->historyFailures[$id->value];
        }

        return $this->history->sliceForWindow($id, $window, $query->detail);
    }

    public function listStates(string|EntityId|Selector|SelectorCollection|EntityFilter|null $selector = null): EntityStateCollection
    {
        return match (true) {
            $selector === null => $this->states,
            $selector instanceof EntityFilter => $this->states->filterByEntityFilter($selector, $this->registry),
            default => $this->states->filterBySelector(Selector::fromSpec($selector)),
        };
    }

    public function watchStateChanges(string|EntityId|Selector|SelectorCollection|EntityFilter $selector): StateChangeStream
    {
        $registry = $this->registry;

        if ($selector instanceof EntityFilter) {
            $matches = static fn(StateChange $change): bool => $selector->matchesEntity($change->entityId, $registry);
        } else {
            $matcher = Selector::fromSpec($selector);
            $matches = static fn(StateChange $change): bool => $matcher->matches($change->entityId->value);
        }

        return new StateChanges($this->stateChanges, $this->timers)->filter($matches);
    }

    public function getRegistry(): Registry
    {
        return $this->registry;
    }

    public function watchEvents(string|Selector|SelectorCollection $eventType): EventStream
    {
        $matcher = EventTypeSelector::fromSpec($eventType)->selector;

        return new OperatorStream($this->events, $this->timers)
            ->filter(static fn(HaEvent $event): bool => $matcher->matches($event->type));
    }

    public function watchTrigger(HaTrigger|HaTriggerCollection|array $trigger, array $variables = []): EventStream
    {
        $spec = TriggerSpec::fromSpec($trigger, $variables);
        $this->watchedTriggers = $this->watchedTriggers->withTriggerSpec($spec);

        return new OperatorStream($this->triggers, $this->timers)
            ->filter(static fn(PushedTrigger $pushed): bool => $pushed->spec->hasSameTriggersAs($spec))
            ->map(static fn(PushedTrigger $pushed): TriggerEvent => $pushed->event);
    }

    public function callService(string $domain, string $service, array $data = [], ?ServiceTargetSource $target = null): EventContext
    {
        return $this->callStubbedService($domain, $service, $data, $target, false)->context ?? EventContext::unknown();
    }

    public function callServiceForResponse(string $domain, string $service, array $data = [], ?ServiceTargetSource $target = null): ServiceResponse
    {
        return $this->callStubbedService($domain, $service, $data, $target, true);
    }

    public function fireEvent(string $eventType, array $data = []): EventContext
    {
        $payload = new EventPayload($eventType, $data);

        if (!$this->connected) {
            throw EventFireException::unreachable($eventType, 'Home Assistant is disconnected');
        }

        $fire = new RecordedEventFire($payload->eventType, $payload->data, $this->issueContext());
        $this->firedEvents = $this->firedEvents->withRecordedFire($fire);

        if (isset($this->eventFireFailures[$eventType])) {
            throw $this->eventFireFailures[$eventType];
        }

        $this->events->push(new HaEvent($fire->eventType, $fire->data, EventOrigin::Remote, $this->clock->getNow(), $fire->context));

        return $fire->context;
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
     * @throws ServiceCallException
     */
    private function callStubbedService(string $domain, string $service, array $data, ?ServiceTargetSource $target, bool $returnsResponse): ServiceResponse
    {
        if (!$this->connected) {
            throw ServiceCallException::unreachable($domain, $service, 'Home Assistant is disconnected');
        }

        $call = new RecordedServiceCall(
            $domain,
            $service,
            ServiceFields::fromFieldsDroppingNulls($data)->fields,
            $target?->toServiceTarget(),
            $returnsResponse,
            $this->issueContext(),
        );

        $this->calls = $this->calls->withRecordedCall($call);
        $outcome = $this->serviceOutcomes[$call->getServiceName()] ?? [];

        return $outcome instanceof ServiceCallException ? throw $outcome : new ServiceResponse($domain, $service, $outcome, $call->context);
    }

    private function issueContext(): EventContext
    {
        return new EventContext(self::CONTEXT_ID_PREFIX . ++$this->issuedContextCount, userId: self::STEWART_USER_ID);
    }
}
