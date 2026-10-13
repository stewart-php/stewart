<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Control;

use RuntimeException;
use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Control\SnapshotSource;

final readonly class FailingSnapshotSource implements SnapshotSource
{
    public function takeSnapshot(): RuntimeSnapshot
    {
        throw new RuntimeException('assembler broke');
    }
}
