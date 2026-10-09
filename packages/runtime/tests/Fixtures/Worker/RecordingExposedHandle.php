<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Worker;

use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Runtime\Worker\Exposure\ExposedHandle;

final class RecordingExposedHandle implements ExposedHandle
{
    /** @var list<ExposedEntitySnapshot> */
    public array $snapshots = [];

    public bool $released = false;

    public function applySnapshot(ExposedEntitySnapshot $snapshot): void
    {
        $this->snapshots[] = $snapshot;
    }

    public function markReleased(): void
    {
        $this->released = true;
    }
}
