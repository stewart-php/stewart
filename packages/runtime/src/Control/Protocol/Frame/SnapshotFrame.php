<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Frame;

use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;

final readonly class SnapshotFrame implements ServerFrame
{
    public function __construct(public RuntimeSnapshot $snapshot) {}
}
