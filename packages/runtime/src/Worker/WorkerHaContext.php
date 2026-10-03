<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\Entity;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Event\EventTypeSelector;
use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exception\StateException;
use Stewart\Contracts\Exception\TopicException;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Selector\Collection\SelectorCollection;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\Service\ServiceFields;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Contracts\Service\ServiceTargetSource;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\StateChangeStream;
use Stewart\Contracts\Topic\TopicPayload;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\State\StateCache;
use Stewart\Runtime\Worker\Context\DispatchStreams;
use Stewart\Runtime\Worker\Context\ServiceCaller;
use Stewart\Runtime\Worker\Context\TopicPublisher;

final readonly class WorkerHaContext implements HaContext
{
    public function __construct(
        private StateCache $states,
        private ConnectionStatus $connection,
        private ServiceCaller $serviceCalls,
        private DispatchStreams $streams,
        private TopicPublisher $topics,
        private ResourceScope $resourceScope,
    ) {}

    public function forApp(AppId $appId): self
    {
        return new self($this->states, $this->connection, $this->serviceCalls, $this->streams, $this->topics, ResourceScope::forApp($appId));
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

    public function listStates(string|EntityId|Selector|SelectorCollection|null $selector = null): EntityStateCollection
    {
        return $this->states->filterBySelector($selector === null ? null : Selector::fromSpec($selector));
    }

    public function watchStateChanges(string|EntityId|Selector|SelectorCollection $selector): StateChangeStream
    {
        return $this->streams->watchStateChanges($this->resourceScope, Selector::fromSpec($selector));
    }

    public function watchEvents(string|Selector|SelectorCollection $eventType): EventStream
    {
        return $this->streams->watchEvents($this->resourceScope, EventTypeSelector::fromSpec($eventType));
    }

    public function callService(string $domain, string $service, array $data = [], ?ServiceTargetSource $target = null): void
    {
        $this->serviceCalls->callService($this->resourceScope, $domain, $service, ServiceFields::fromFieldsDroppingNulls($data), $target?->toServiceTarget());
    }

    public function callServiceForResponse(string $domain, string $service, array $data = [], ?ServiceTargetSource $target = null): ServiceResponse
    {
        return $this->serviceCalls->callServiceForResponse($this->resourceScope, $domain, $service, ServiceFields::fromFieldsDroppingNulls($data), $target?->toServiceTarget());
    }

    public function publish(string $topic, bool|int|float|string|array|null $payload = null): void
    {
        $topicPayload = new TopicPayload($payload);

        try {
            $this->topics->publish($this->resourceScope, $topic, $topicPayload);
        } catch (TransportException $e) {
            throw TopicException::publishFailed($topic, $e);
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
