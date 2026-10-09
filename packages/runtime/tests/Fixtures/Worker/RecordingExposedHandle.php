<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Worker;

use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Runtime\Worker\Exposure\ExposedHandle;

final class RecordingExposedHandle implements ExposedHandle
{
    /** @var list<ExposedEntitySnapshot> */
    public array $snapshots = [];

    /** @var list<ExposedState> */
    public array $commandedStates = [];

    public bool $released = false;

    public function applySnapshot(ExposedEntitySnapshot $snapshot): void
    {
        $this->snapshots[] = $snapshot;
    }

    public function recordCommandedState(ExposedState $state): void
    {
        $this->commandedStates[] = $state;
    }

    public function markReleased(): void
    {
        $this->released = true;
    }
}
