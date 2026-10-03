<?php

declare(strict_types=1);

namespace Stewart\Testing\Tests\Unit\Time;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\Time\ManualTimeout;
use Stewart\Testing\Time\ManualTimer;
use Stewart\Testing\Time\ManualTimers;

#[CoversClass(ManualTimers::class)]
#[CoversClass(ManualTimer::class)]
#[CoversClass(ManualTimeout::class)]
final class ManualTimersTest extends TestCase
{
    private ManualTimers $timers;

    /** @var list<string> */
    private array $fired = [];

    protected function setUp(): void
    {
        $this->timers = new ManualTimers();
        $this->fired = [];
    }

    public function testTimerFiresOnlyOnceTheClockReachesIt(): void
    {
        $this->timers->startTimer(Duration::milliseconds(300), $this->record('a'));

        $this->timers->delay(Duration::milliseconds(299));
        self::assertSame([], $this->fired);

        $this->timers->delay(Duration::milliseconds(1));
        self::assertSame(['a'], $this->fired);
    }

    public function testTimersFireInDueOrderNotRegistrationOrder(): void
    {
        $this->timers->startTimer(Duration::milliseconds(300), $this->record('late'));
        $this->timers->startTimer(Duration::milliseconds(100), $this->record('early'));
        $this->timers->startTimer(Duration::milliseconds(200), $this->record('middle'));

        $this->timers->delay(Duration::seconds(1));

        self::assertSame(['early', 'middle', 'late'], $this->fired);
    }

    public function testTimerScheduledInCallbackFiresInSameAdvance(): void
    {
        $this->timers->startTimer(Duration::milliseconds(100), function (): void {
            $this->fired[] = 'first';
            $this->timers->startTimer(Duration::milliseconds(100), $this->record('second'));
        });

        $this->timers->delay(Duration::milliseconds(250));

        self::assertSame(['first', 'second'], $this->fired, 'The clock is at 100ms when the first fires, so the second is due at 200ms.');
    }

    public function testRescheduledTimerCountsFromCurrentVirtualTime(): void
    {
        $this->timers->startTimer(Duration::milliseconds(100), function (): void {
            $this->timers->startTimer(Duration::milliseconds(100), $this->record('chained'));
        });

        $this->timers->delay(Duration::milliseconds(150));
        self::assertSame([], $this->fired, 'The chained timer is due at 200ms, not 150ms.');

        $this->timers->delay(Duration::milliseconds(50));
        self::assertSame(['chained'], $this->fired);
    }

    public function testCancelledTimerNeverFiresAndIsForgotten(): void
    {
        $timer = $this->timers->startTimer(Duration::milliseconds(100), $this->record('cancelled'));

        self::assertSame(1, $this->timers->countPendingTimers());

        $timer->cancel();

        self::assertFalse($timer->isPending());
        self::assertSame(0, $this->timers->countPendingTimers(), 'Cancelling releases the timer rather than leaving it to be skipped later.');

        $this->timers->delay(Duration::seconds(1));

        self::assertSame([], $this->fired);
    }

    public function testCancellingTwiceIsHarmless(): void
    {
        $timer = $this->timers->startTimer(Duration::milliseconds(100), $this->record('x'));

        $timer->cancel();
        $timer->cancel();

        self::assertSame(0, $this->timers->countPendingTimers());
    }

    public function testFiredTimerIsNotPendingAndCancelIsNoop(): void
    {
        $timer = $this->timers->startTimer(Duration::milliseconds(100), $this->record('x'));

        $this->timers->delay(Duration::milliseconds(100));

        self::assertSame(['x'], $this->fired);
        self::assertFalse($timer->isPending());
        self::assertSame(0, $this->timers->countPendingTimers());

        $timer->cancel();

        self::assertSame(['x'], $this->fired);
    }

    public function testClockAdvancesFullSpanWhenNothingIsDue(): void
    {
        $this->timers->delay(Duration::seconds(5));

        self::assertSame(5_000_000, $this->timers->clock->getMonotonicTime()->toMicroseconds());
    }

    public function testTimeoutCancelsOnceItsLimitPasses(): void
    {
        $cancellation = $this->timers->timeout(Duration::seconds(2));

        $this->timers->delay(Duration::milliseconds(1_999));
        self::assertFalse($cancellation->isRequested());

        $this->timers->delay(Duration::milliseconds(1));
        self::assertTrue($cancellation->isRequested());
        self::assertSame(0, $this->timers->countPendingTimers());
    }

    public function testAbandonedTimeoutReleasesItsTimer(): void
    {
        $cancellation = $this->timers->timeout(Duration::seconds(2));
        self::assertSame(1, $this->timers->countPendingTimers());

        unset($cancellation);

        self::assertSame(0, $this->timers->countPendingTimers());
    }

    public function testDelayStopsAtTheTimerThatCancelsIt(): void
    {
        $stop = new DeferredCancellation();
        $this->timers->startTimer(Duration::seconds(1), static function () use ($stop): void {
            $stop->cancel();
        });
        $this->timers->startTimer(Duration::seconds(2), $this->record('late'));

        try {
            $this->timers->delay(Duration::seconds(3), $stop->getCancellation());
            self::fail('The delay was cancelled after one second.');
        } catch (CancelledException) {
        }

        self::assertSame([], $this->fired);
        self::assertSame(1_000_000, $this->timers->clock->getMonotonicTime()->toMicroseconds());
    }

    public function testDelayLetsVirtualTimePass(): void
    {
        $this->timers->startTimer(Duration::seconds(1), $this->record('due'));

        $this->timers->delay(Duration::seconds(3));

        self::assertSame(['due'], $this->fired);
        self::assertSame(3_000_000, $this->timers->clock->getMonotonicTime()->toMicroseconds());
    }

    /** @return Closure(): void */
    private function record(string $label): Closure
    {
        return function () use ($label): void {
            $this->fired[] = $label;
        };
    }
}
