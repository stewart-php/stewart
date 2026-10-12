<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use DateTimeZone;
use Stewart\Contracts\Sun\GeoLocation;
use Throwable;

interface HaSession
{
    /** @throws Throwable */
    public function open(HaSessionListener $listener): void;

    public function close(): void;

    public function isConnected(): bool;

    public function snapshotStateCache(): StateCacheSnapshot;

    public function snapshotRegistry(): RegistryCacheSnapshot;

    public function refreshRegistry(): bool;

    public function countEntities(): int;

    public function getTimeZone(): DateTimeZone;

    public function getHaVersion(): ?string;

    public function getHaUserId(): ?string;

    public function getLocation(): ?GeoLocation;

    /** @return list<string> */
    public function listEntityIds(): array;
}
