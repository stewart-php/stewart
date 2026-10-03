<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Time;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Time\RepeatingTimer;
use Stewart\Testing\Time\ManualTimers;
use Throwable;

#[CoversClass(RepeatingTimer::class)]
final class RepeatingTimerTest extends TestCase
{
    private ManualTimers $timers;

    private int $ticks = 0;

    protected function setUp(): void
    {
        $this->timers = new ManualTimers();
    }

    public function testItTicksOncePerIntervalUntilStopped(): void
    {
        $timer = new RepeatingTimer($this->timers, Duration::seconds(5), $this->tick(...), $this->failOnTickError(...));
        $timer->start();

        $this->timers->delay(Duration::seconds(4));
        self::assertSame(0, $this->ticks);
        self::assertTrue($timer->isRunning());

        $this->timers->delay(Duration::seconds(11));
        self::assertSame(3, $this->ticks);

        $timer->stop();
        $this->timers->delay(Duration::seconds(30));

        self::assertSame(3, $this->ticks);
        self::assertFalse($timer->isRunning());
        self::assertSame(0, $this->timers->countPendingTimers());
    }

    public function testTickCanStopItsOwnTimer(): void
    {
        $timer = null;
        $timer = new RepeatingTimer($this->timers, Duration::seconds(1), function () use (&$timer): void {
            $this->tick();
            $timer?->stop();
        }, $this->failOnTickError(...));
        $timer->start();

        $this->timers->delay(Duration::seconds(10));

        self::assertSame(1, $this->ticks);
        self::assertFalse($timer->isRunning());
        self::assertSame(0, $this->timers->countPendingTimers());
    }

    public function testThrowingTickIsReportedAndKeepsTicking(): void
    {
        $failures = [];
        $timer = new RepeatingTimer($this->timers, Duration::seconds(1), function (): void {
            $this->tick();

            throw new RuntimeException('probe failed');
        }, static function (Throwable $e) use (&$failures): void {
            $failures[] = $e->getMessage();
        });
        $timer->start();

        $this->timers->delay(Duration::seconds(2));

        self::assertSame(2, $this->ticks);
        self::assertSame(['probe failed', 'probe failed'], $failures);
        self::assertTrue($timer->isRunning());
    }

    private function tick(): void
    {
        ++$this->ticks;
    }

    private function failOnTickError(Throwable $e): never
    {
        self::fail('Tick failed: ' . $e->getMessage());
    }
}
