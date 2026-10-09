<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Exposure;

use Stewart\Contracts\Exposure\ExposedEntitySnapshot;

interface ExposedHandle
{
    public function applySnapshot(ExposedEntitySnapshot $snapshot): void;

    public function markReleased(): void;
}
