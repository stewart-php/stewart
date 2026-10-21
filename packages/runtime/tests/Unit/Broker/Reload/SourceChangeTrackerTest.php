<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker\Reload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Broker\Reload\SourceChangeTracker;
use Stewart\Runtime\Broker\Reload\SourceFingerprint;

#[CoversClass(SourceChangeTracker::class)]
final class SourceChangeTrackerTest extends TestCase
{
    public function testChangeSettlesOnTheSecondScanThatAgrees(): void
    {
        $tracker = new SourceChangeTracker(new SourceFingerprint('a'));

        self::assertNull($tracker->findSettledChange(new SourceFingerprint('b')));
        self::assertEquals(new SourceFingerprint('b'), $tracker->findSettledChange(new SourceFingerprint('b')));
    }

    public function testChangeStillBeingWrittenWaits(): void
    {
        $tracker = new SourceChangeTracker(new SourceFingerprint('a'));

        self::assertNull($tracker->findSettledChange(new SourceFingerprint('b')));
        self::assertNull($tracker->findSettledChange(new SourceFingerprint('c')));
        self::assertEquals(new SourceFingerprint('c'), $tracker->findSettledChange(new SourceFingerprint('c')));
    }

    public function testAppliedChangeIsNotReportedAgain(): void
    {
        $tracker = new SourceChangeTracker(new SourceFingerprint('a'));
        $tracker->findSettledChange(new SourceFingerprint('b'));
        $tracker->markApplied(new SourceFingerprint('b'));

        self::assertNull($tracker->findSettledChange(new SourceFingerprint('b')));
        self::assertNull($tracker->findSettledChange(new SourceFingerprint('b')));
    }

    public function testRevertedEditIsNoChange(): void
    {
        $tracker = new SourceChangeTracker(new SourceFingerprint('a'));

        self::assertNull($tracker->findSettledChange(new SourceFingerprint('b')));
        self::assertNull($tracker->findSettledChange(new SourceFingerprint('a')));
        self::assertNull($tracker->findSettledChange(new SourceFingerprint('a')));
    }
}
