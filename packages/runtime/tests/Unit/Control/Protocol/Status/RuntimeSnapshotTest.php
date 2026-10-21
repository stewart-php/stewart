<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Control\Protocol\Status;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Tests\Fixtures\Control\StubSnapshotSource;

#[CoversClass(RuntimeSnapshot::class)]
final class RuntimeSnapshotTest extends TestCase
{
    public function testOnlyTheNamedAppsKeepTheirAppSubscriptionsAndSchedules(): void
    {
        $snapshot = new StubSnapshotSource()->takeSnapshot();

        $kept = $snapshot->withOnlyApps(['porch']);
        $none = $snapshot->withOnlyApps(['hall']);

        self::assertEquals($snapshot, $kept);
        self::assertSame([[], [], []], [$none->apps, $none->subscriptions, $none->schedules]);
        self::assertEquals($snapshot->workers, $none->workers, 'Workers and the daemon stay whole.');
    }
}
