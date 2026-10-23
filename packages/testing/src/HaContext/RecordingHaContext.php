<?php

declare(strict_types=1);

namespace Stewart\Testing\HaContext;

use LogicException;
use Stewart\Contracts\Action\ActionName;
use Stewart\Contracts\Action\ActionResponse;
use Stewart\Contracts\Action\ActionTargetSource;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Connection\ConnectionEvent;
use Stewart\Contracts\Connection\ConnectionLost;
use Stewart\Contracts\Entity\Entity;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Event\EventOrigin;
use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exception\ActionCallException;
use Stewart\Contracts\Exception\EventFireException;
use Stewart\Contracts\Exception\HistoryException;
use Stewart\Contracts\Exception\StateException;
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
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Topic\Collection\TopicEventCollection;
use Stewart\Contracts\Topic\TopicEvent;
use Stewart\Contracts\Trigger\Collection\HaTriggerCollection;
use Stewart\Contracts\Trigger\HaTrigger;
use Stewart\Contracts\Trigger\TriggerEvent;
use Stewart\Support\State\StewartIdentity;
use Stewart\Support\Stream\OperatorStream;
use Stewart\Support\Stream\StateChanges;
use Stewart\Support\Wire\ActionFields;
use Stewart\Support\Wire\EventPayload;
use Stewart\Support\Wire\EventTypeSelector;
use Stewart\Support\Wire\TopicPayload;
use Stewart\Support\Wire\TriggerSpec;
use Stewart\Testing\HaContext\Collection\RecordedActionCallCollection;
use Stewart\Testing\HaContext\Collection\RecordedEventFireCollection;
use Stewart\Testing\HaContext\Collection\RecordedHistoryQueryCollection;
use Stewart\Testing\HaContext\Collection\WatchedTriggerCollection;
use Stewart\Testing\Registry\InMemoryRegistry;
use Stewart\Testing\Stream\PushSource;
use Stewart\Testing\Time\ManualTimers;
use Stewart\Testing\Time\VirtualClock;

final class RecordingHaContext implements HaContext
{
    public const string STEWART_USER_ID = 'stewart-user';

    private const string CONTEXT_ID_PREFIX = 'recorded-';

    public private(set) RecordedActionCallCollection $calls;

    public private(set) TopicEventCollection $published;

    public private(set) RecordedEventFireCollection $firedEvents;

    public private(set) RecordedHistoryQueryCollection $historyQueries;

    private EntityStateCollection $states;

    private readonly SeededHistory $history;

    /** @var array<string, HistoryException> */
    private array $historyFailures = [];

    /** @var array<string, array<string, mixed>|ActionCallException> */
    private array $actionOutcomes = [];

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

    private WatchedTriggerCollection $watchedTriggers;

    private readonly StewartIdentity $identity;

    public private(set) bool $connected = true;

    public readonly VirtualClock $clock;

    public readonly InMemoryRegistry $registry;

    public function __construct(private readonly ManualTimers $timers = new ManualTimers())
    {
        $this->clock = $timers->clock;
        $this->registry = new InMemoryRegistry();
        $this->calls = RecordedActionCallCollection::empty();
        $this->published = TopicEventCollection::empty();
        $this->firedEvents = RecordedEventFireCollection::empty();
        $this->states = EntityStateCollection::empty();
        $this->historyQueries = RecordedHistoryQueryCollection::empty();
        $this->history = new SeededHistory();
        $this->stateChanges = new PushSource($timers->handlerFibers);
        $this->events = new PushSource($timers->handlerFibers);
        $this->topics = new PushSource($timers->handlerFibers);
        $this->connectionEvents = new PushSource($timers->handlerFibers);
        $this->triggers = new PushSource($timers->handlerFibers);
        $this->watchedTriggers = WatchedTriggerCollection::empty();
        $this->identity = new StewartIdentity(self::STEWART_USER_ID);
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

    /**
     * @param array<string, mixed> $response
     * @throws ActionCallException
     */
    public function stubActionAnswer(string $action, array $response): self
    {
        $this->actionOutcomes[new ActionName($action)->value] = $response;

        return $this;
    }

    /** @throws ActionCallException */
    public function stubActionFailure(string $action, ActionCallException $failure): self
    {
        $this->actionOutcomes[new ActionName($action)->value] = $failure;

        return $this;
    }

    public function stubEventFireFailure(string $eventType, EventFireException $failure): self
    {
        $this->eventFireFailures[$eventType] = $failure;

        return $this;
    }

    public function cancelSubscriptions(): void
    {
        foreach ([$this->stateChanges, $this->events, $this->topics, $this->connectionEvents, $this->triggers] as $source) {
            $source->cancelAttachments();
        }
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

    public function listWatchedTriggers(): WatchedTriggerCollection
    {
        return $this->watchedTriggers;
    }

    public function pushConnection(ConnectionEvent $event): void
    {
        $this->connected = !$event instanceof ConnectionLost;
        $this->connectionEvents->push($event);
    }

    /** @throws LogicException */
    public function getLastCall(): RecordedActionCall
    {
        return $this->calls->getLast() ?? throw new LogicException('No action has been called.');
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

        return new StateChanges($this->stateChanges, $this->timers, new RecordedStateReader($this, $selector))->filter($matches);
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
        $matcher = EventTypeSelector::fromSpec($eventType)->selector;

        return new OperatorStream($this->events, $this->timers)
            ->filter(static fn(HaEvent $event): bool => $matcher->matches($event->type));
    }

    public function watchTrigger(HaTrigger|HaTriggerCollection|array $trigger, array $variables = []): EventStream
    {
        $spec = TriggerSpec::fromSpec($trigger, $variables);
        $this->watchedTriggers = $this->watchedTriggers->withWatchedTrigger(new WatchedTrigger($spec->triggers, $spec->variables));

        return new OperatorStream($this->triggers, $this->timers)
            ->filter(static fn(PushedTrigger $pushed): bool => $pushed->spec->hasSameTriggersAs($spec))
            ->map(static fn(PushedTrigger $pushed): TriggerEvent => $pushed->event);
    }

    public function callAction(string $action, array $data = [], ?ActionTargetSource $target = null): EventContext
    {
        return $this->callStubbedAction(new ActionName($action), $data, $target, false)->context ?? EventContext::unknown();
    }

    public function callActionForResponse(string $action, array $data = [], ?ActionTargetSource $target = null): ActionResponse
    {
        return $this->callStubbedAction(new ActionName($action), $data, $target, true);
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

    public function wasCausedByStewart(StateChange|EntityState|HaEvent $subject): bool
    {
        return $this->identity->wasCausedByStewart($subject);
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
     * @throws ActionCallException
     */
    private function callStubbedAction(ActionName $action, array $data, ?ActionTargetSource $target, bool $returnsResponse): ActionResponse
    {
        if (!$this->connected) {
            throw ActionCallException::unreachable($action->domain, $action->action, 'Home Assistant is disconnected');
        }

        $call = new RecordedActionCall(
            $action->domain,
            $action->action,
            ActionFields::fromFieldsDroppingNulls($data)->fields,
            $target?->toActionTarget(),
            $returnsResponse,
            $this->issueContext(),
        );

        $this->calls = $this->calls->withRecordedCall($call);
        $outcome = $this->actionOutcomes[$action->value] ?? [];

        return $outcome instanceof ActionCallException ? throw $outcome : new ActionResponse($action->domain, $action->action, $outcome, $call->context);
    }

    private function issueContext(): EventContext
    {
        return new EventContext(self::CONTEXT_ID_PREFIX . ++$this->issuedContextCount, userId: self::STEWART_USER_ID);
    }
}
