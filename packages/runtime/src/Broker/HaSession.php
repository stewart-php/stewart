<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use DateTimeZone;
use Stewart\Contracts\Exception\ServiceCallException;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Contracts\Service\ServiceTarget;
use Throwable;

interface HaSession
{
    /** @throws Throwable */
    public function open(HaSessionListener $listener): void;

    public function close(): void;

    public function isConnected(): bool;

    public function snapshotStateCache(): StateCacheSnapshot;

    public function countEntities(): int;

    public function getTimeZone(): DateTimeZone;

    public function getHaVersion(): ?string;

    /** @return list<string> */
    public function listEntityIds(): array;

    /**
     * @param array<string, mixed> $data
     * @throws ServiceCallException
     */
    public function callService(
        string $domain,
        string $service,
        array $data = [],
        ?ServiceTarget $target = null,
        bool $returnResponse = false,
    ): ServiceResponse;
}
