<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Exposure;

use Stewart\Client\Component\ExposedEntityAddress;
use Stewart\Client\Component\ExposedEntityDefinition;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Runtime\Model\WorkerId;

final class LiveExposure
{
    public function __construct(
        public private(set) ?WorkerId $owner,
        public readonly ExposedEntityAddress $address,
        public readonly ExposedEntityDefinition $definition,
        public private(set) ExposedStateChange $latestChange,
    ) {}

    public function recordChange(ExposedStateChange $change): void
    {
        $this->latestChange = $this->latestChange->withLaterChange($change);
    }

    public function markOrphaned(): void
    {
        $this->owner = null;
    }

    public function isOrphaned(): bool
    {
        return $this->owner === null;
    }

    public function isOwnedBy(WorkerId $workerId): bool
    {
        return $this->owner?->equals($workerId) === true;
    }

    public function buildUpsertChange(): ExposedStateChange
    {
        return $this->isOrphaned() ? $this->latestChange->withLaterChange(new ExposedStateChange(available: false)) : $this->latestChange;
    }
}
