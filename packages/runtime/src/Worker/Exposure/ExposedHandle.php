<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Exposure;

use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Contracts\Exposure\ExposedState;

interface ExposedHandle
{
    public function applySnapshot(ExposedEntitySnapshot $snapshot): void;

    public function recordCommandedState(ExposedState $state): void;

    public function markReleased(): void;
}
