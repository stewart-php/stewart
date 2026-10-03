<?php

declare(strict_types=1);

namespace Stewart\Support\Tests\Unit\Time;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Stewart\Contracts\Time\Duration;
use Stewart\Support\Time\RevoltTimerHandle;
use Stewart\Support\Time\RevoltTimers;

use function Amp\delay;

#[CoversClass(RevoltTimers::class)]
#[CoversClass(RevoltTimerHandle::class)]
final class RevoltTimersTest extends TestCase
{
    private RevoltTimers $timers;

    protected function setUp(): void
    {
        $this->timers = new RevoltTimers();
    }

    public function testTimerFiresAfterItsDelay(): void
    {
        $fired = [];

        $timer = $this->timers->startTimer(Duration::milliseconds(10), static function () use (&$fired): void {
            $fired[] = true;
        });

        self::assertTrue($timer->isPending());
        self::assertSame([], $fired);

        delay(0.015);

        self::assertSame([true], $fired);
        self::assertFalse($timer->isPending(), 'A one-shot timer is spent once it has run.');
    }

    public function testCancelledTimerNeverFires(): void
    {
        $fired = [];

        $timer = $this->timers->startTimer(Duration::milliseconds(10), static function () use (&$fired): void {
            $fired[] = true;
        });

        $timer->cancel();

        self::assertFalse($timer->isPending());

        delay(0.015);

        self::assertSame([], $fired);
    }

    public function testCancellingTwiceIsHarmless(): void
    {
        $timer = $this->timers->startTimer(Duration::seconds(10), static fn() => null);

        $timer->cancel();
        $timer->cancel();

        self::assertFalse($timer->isPending());
    }

    public function testCancellingAFiredTimerIsHarmless(): void
    {
        $timer = $this->timers->startTimer(Duration::milliseconds(10), static fn() => null);

        delay(0.015);

        $timer->cancel();

        self::assertFalse($timer->isPending());
    }

    public function testPendingTimerIsUnreferenced(): void
    {
        // A referenced debounce would keep EventLoop::run() and the worker alive past shutdown.
        $before = EventLoop::getIdentifiers();

        $timer = $this->timers->startTimer(Duration::hours(1), static fn() => null);

        $added = array_values(array_diff(EventLoop::getIdentifiers(), $before));

        self::assertCount(1, $added, 'The timer should be registered with the loop.');
        self::assertFalse(EventLoop::isReferenced($added[0]), 'Every operator timer must be unreferenced.');

        $timer->cancel();
    }

    public function testTimeoutIsRequestedOnceItsLimitPasses(): void
    {
        $cancellation = $this->timers->timeout(Duration::milliseconds(10));

        self::assertFalse($cancellation->isRequested());

        delay(0.015);

        self::assertTrue($cancellation->isRequested());
    }

    public function testDelayKeepsTheLoopAliveUntilItEnds(): void
    {
        $before = hrtime(true);

        $this->timers->delay(Duration::milliseconds(20));

        self::assertGreaterThanOrEqual(20_000_000, hrtime(true) - $before);
    }

    public function testStoppedDelayEndsWithoutWaiting(): void
    {
        $stop = new DeferredCancellation();
        $stop->cancel();

        $this->expectException(CancelledException::class);

        $this->timers->delay(Duration::seconds(60), $stop->getCancellation());
    }
}
