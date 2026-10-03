<?php

declare(strict_types=1);

namespace Stewart\Testing\Tests\Unit\Time;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Testing\Time\EventLoopTicks;

use function Amp\async;
use function Amp\delay;

#[CoversClass(EventLoopTicks::class)]
final class EventLoopTicksTest extends TestCase
{
    public function testSettleUntilWaitsForAsyncWork(): void
    {
        $done = false;
        async(static function () use (&$done): void {
            delay(0);
            delay(0);
            delay(0);
            $done = true;
        });

        EventLoopTicks::settleUntil(static function () use (&$done): bool {
            return $done;
        });

        self::assertTrue($done);
    }

    public function testSettleUntilFailsOnceTheBoundIsHit(): void
    {
        $this->expectException(AssertionFailedError::class);

        EventLoopTicks::settleUntil(static fn(): bool => false, 3);
    }
}
