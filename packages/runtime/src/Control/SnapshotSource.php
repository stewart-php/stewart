<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control;

use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;

interface SnapshotSource
{
    public function takeSnapshot(): RuntimeSnapshot;
}
