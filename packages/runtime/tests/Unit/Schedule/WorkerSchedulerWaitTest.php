<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Schedule;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\PendingWait;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Schedule\WorkerScheduler;
use Stewart\Runtime\Tests\Fixtures\Schedule\WorkerSchedulerFixture;
use Stewart\Runtime\Tests\Fixtures\Worker\AppResourcesFixture;
use Stewart\Sun\UnlocatedSunCalendar;
use Stewart\Testing\Time\EventLoopTicks;

use function Amp\async;

#[CoversClass(WorkerScheduler::class)]
final class WorkerSchedulerWaitTest extends TestCase
{
    private AppResourcesFixture $fixture;

    private WorkerScheduler $scheduler;

    protected function setUp(): void
    {
        $this->fixture = new AppResourcesFixture();
        $this->scheduler = WorkerSchedulerFixture::createWorkerScheduler($this->fixture->schedules, new UnlocatedSunCalendar(), new NullLogger(), ResourceScope::shared(), $this->fixture->timers)
            ->forApp(new AppId('porch'), new NullLogger());
    }

    public function testWaitEndsWithTheResolvedValue(): void
    {
        $outcome = async(fn(): mixed => $this->scheduler->waitFor(function (PendingWait $wait): Closure {
            $this->fixture->timers->startTimer(Duration::seconds(2), static fn() => $wait->resolve('arrived'));

            return static fn() => null;
        }, Duration::seconds(5)));
        EventLoopTicks::settle();

        $this->fixture->timers->delay(Duration::seconds(2));

        self::assertSame('arrived', $outcome->await());
    }

    public function testWaitTimesOutWhileItsScopeIsNotLive(): void
    {
        $outcome = async(fn(): mixed => $this->scheduler->waitFor(static fn(PendingWait $wait): Closure => static fn() => null, Duration::seconds(5)));
        EventLoopTicks::settle();

        $this->fixture->timers->delay(Duration::seconds(5));

        self::assertNull($outcome->await(), 'A wait times out on the loop timers, which a paused or starting app does not hold back.');
    }
}
