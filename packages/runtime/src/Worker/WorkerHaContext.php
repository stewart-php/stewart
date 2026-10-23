<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

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
use Stewart\Contracts\Service\ActionName;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Contracts\Service\ServiceTargetSource;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\EventContext;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\StateChangeStream;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Trigger\Collection\HaTriggerCollection;
use Stewart\Contracts\Trigger\HaTrigger;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Registry\RegistryCache;
use Stewart\Runtime\State\StateCache;
use Stewart\Runtime\Worker\Context\DispatchStreams;
use Stewart\Runtime\Worker\Context\EventFirer;
use Stewart\Runtime\Worker\Context\HistoryReader;
use Stewart\Runtime\Worker\Context\ServiceCaller;
use Stewart\Runtime\Worker\Context\TopicPublisher;
use Stewart\Support\State\StewartIdentity;
use Stewart\Support\Wire\EventPayload;
use Stewart\Support\Wire\EventTypeSelector;
use Stewart\Support\Wire\ServiceFields;
use Stewart\Support\Wire\TopicPayload;
use Stewart\Support\Wire\TriggerSpec;

final readonly class WorkerHaContext implements HaContext
{
    public function __construct(
        private StateCache $states,
        private RegistryCache $registry,
        private ConnectionStatus $connection,
        private ServiceCaller $serviceCalls,
        private HistoryReader $history,
        private EventFirer $eventFires,
        private DispatchStreams $streams,
        private TopicPublisher $topics,
        private StewartIdentity $identity,
        private ResourceScope $resourceScope,
    ) {}

    public function forApp(AppId $appId): self
    {
        return new self($this->states, $this->registry, $this->connection, $this->serviceCalls, $this->history, $this->eventFires, $this->streams, $this->topics, $this->identity, ResourceScope::forApp($appId));
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

    public function callAction(string $action, array $data = [], ?ServiceTargetSource $target = null): EventContext
    {
        $name = new ActionName($action);

        return $this->serviceCalls->callService($this->resourceScope, $name->domain, $name->action, ServiceFields::fromFieldsDroppingNulls($data), $target?->toServiceTarget());
    }

    public function callActionForResponse(string $action, array $data = [], ?ServiceTargetSource $target = null): ServiceResponse
    {
        $name = new ActionName($action);

        return $this->serviceCalls->callServiceForResponse($this->resourceScope, $name->domain, $name->action, ServiceFields::fromFieldsDroppingNulls($data), $target?->toServiceTarget());
    }

    public function callService(string $domain, string $service, array $data = [], ?ServiceTargetSource $target = null): EventContext
    {
        return $this->serviceCalls->callService($this->resourceScope, $domain, $service, ServiceFields::fromFieldsDroppingNulls($data), $target?->toServiceTarget());
    }

    public function callServiceForResponse(string $domain, string $service, array $data = [], ?ServiceTargetSource $target = null): ServiceResponse
    {
        return $this->serviceCalls->callServiceForResponse($this->resourceScope, $domain, $service, ServiceFields::fromFieldsDroppingNulls($data), $target?->toServiceTarget());
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
