<?php

declare(strict_types=1);

namespace Stewart\Contracts;

use Stewart\Contracts\Connection\ConnectionEvent;
use Stewart\Contracts\Entity\Entity;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\Exception\HistoryException;
use Stewart\Contracts\Exception\IdentifierException;
use Stewart\Contracts\Exception\SelectorException;
use Stewart\Contracts\Exception\ServiceCallException;
use Stewart\Contracts\Exception\StateException;
use Stewart\Contracts\Exception\TopicException;
use Stewart\Contracts\History\EntityStateHistory;
use Stewart\Contracts\History\HistoryQuery;
use Stewart\Contracts\Selector\Collection\SelectorCollection;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Contracts\Service\ServiceTargetSource;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\Topic\TopicEvent;

interface HaContext
{
    /** @throws IdentifierException */
    public function getState(EntityId|string $entityId): ?EntityState;

    /** @throws IdentifierException */
    public function getEntity(EntityId|string $entityId): Entity;

    /** @throws StateException|IdentifierException */
    public function requireState(EntityId|string $entityId): EntityState;

    /** @throws HistoryException|IdentifierException */
    public function getHistory(EntityId|string $entityId, HistoryQuery $query): EntityStateHistory;

    public function listStates(string|EntityId|Selector|SelectorCollection|null $selector = null): EntityStateCollection;

    public function watchStateChanges(string|EntityId|Selector|SelectorCollection $selector): StateChangeStream;

    /**
     * @return EventStream<HaEvent>
     * @throws StateException|SelectorException
     */
    public function watchEvents(string|Selector|SelectorCollection $eventType): EventStream;

    /**
     * @param array<string, mixed> $data
     * @throws ServiceCallException
     */
    public function callService(string $domain, string $service, array $data = [], ?ServiceTargetSource $target = null): void;

    /**
     * @param array<string, mixed> $data
     * @throws ServiceCallException
     */
    public function callServiceForResponse(string $domain, string $service, array $data = [], ?ServiceTargetSource $target = null): ServiceResponse;

    /**
     * @param array<array-key, mixed>|scalar|null $payload
     * @throws TopicException
     */
    public function publish(string $topic, bool|int|float|string|array|null $payload = null): void;

    /** @return EventStream<TopicEvent> */
    public function watchTopic(string|Selector|SelectorCollection $topic): EventStream;

    /** @return EventStream<ConnectionEvent> */
    public function watchConnection(): EventStream;

    public function isConnected(): bool;
}
