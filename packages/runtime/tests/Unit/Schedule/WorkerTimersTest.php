<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Schedule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Schedule\ScheduledTimerHandle;
use Stewart\Runtime\Schedule\WorkerScheduler;
use Stewart\Runtime\Schedule\WorkerTimers;
use Stewart\Runtime\Tests\Fixtures\Worker\AppResourcesFixture;
use Stewart\Runtime\Tests\Fixtures\Worker\RecordingScheduleListener;
use Stewart\Sun\UnlocatedSunCalendar;

#[CoversClass(WorkerTimers::class)]
#[CoversClass(ScheduledTimerHandle::class)]
final class WorkerTimersTest extends TestCase
{
    private RecordingScheduleListener $listener;

    private AppResourcesFixture $fixture;

    private WorkerTimers $timers;

    private int $fired = 0;

    protected function setUp(): void
    {
        $this->listener = new RecordingScheduleListener();
        $this->fixture = new AppResourcesFixture(scheduleListener: $this->listener);
        $this->timers = new WorkerTimers(new WorkerScheduler($this->fixture->schedules, new UnlocatedSunCalendar(), new NullLogger(), self::createScope()));
        $this->fired = 0;
    }

    public function testTimerWaitsUntilItsScopeIsLive(): void
    {
        $this->timers->startTimer(Duration::seconds(5), $this->countFiring(...));
        $this->fixture->timers->delay(Duration::seconds(10));

        self::assertSame(0, $this->fired, 'Nothing of an app runs before it is live.');

        $this->fixture->resources->activateScope(self::createScope());
        $this->fixture->timers->delay(Duration::seconds(4));

        self::assertSame(0, $this->fired, 'The delay counts from going live.');

        $this->fixture->timers->delay(Duration::seconds(1));

        self::assertSame(1, $this->fired);
    }

    public function testHandleStaysPendingUntilItFires(): void
    {
        $this->fixture->resources->activateScope(self::createScope());
        $handle = $this->timers->startTimer(Duration::seconds(5), $this->countFiring(...));

        self::assertTrue($handle->isPending());

        $this->fixture->timers->delay(Duration::seconds(5));

        self::assertFalse($handle->isPending());
        self::assertSame(1, $this->fired);
    }

    public function testCancelledTimerNeverFires(): void
    {
        $this->fixture->resources->activateScope(self::createScope());
        $handle = $this->timers->startTimer(Duration::seconds(5), $this->countFiring(...));

        $handle->cancel();
        $this->fixture->timers->delay(Duration::seconds(10));

        self::assertFalse($handle->isPending());
        self::assertSame(0, $this->fired);
    }

    public function testThrowingCallbackIsReportedAsRunFailure(): void
    {
        $failure = new RuntimeException('timer blew up');
        $this->fixture->resources->activateScope(self::createScope());

        $this->timers->startTimer(Duration::seconds(1), static function () use ($failure): void {
            throw $failure;
        });
        $this->fixture->timers->delay(Duration::seconds(1));

        self::assertCount(1, $this->listener->failedRuns);
        self::assertTrue($this->listener->failedRuns[0]->scope->equals(self::createScope()));
        self::assertSame([$failure], $this->listener->errors);
    }

    public function testReleasedScopeTimerNeverFires(): void
    {
        $this->fixture->resources->activateScope(self::createScope());
        $handle = $this->timers->startTimer(Duration::seconds(5), $this->countFiring(...));

        $this->fixture->resources->releaseScope(self::createScope());
        $this->fixture->timers->delay(Duration::seconds(10));

        self::assertFalse($handle->isPending());
        self::assertSame(0, $this->fired);
        self::assertFalse($this->timers->startTimer(Duration::seconds(1), $this->countFiring(...))->isPending());
    }

    public function testSubMillisecondDelayIsFlooredToOne(): void
    {
        $this->fixture->resources->activateScope(self::createScope());

        $this->timers->startTimer(Duration::zero(), $this->countFiring(...));
        $this->fixture->timers->delay(Duration::milliseconds(1));

        self::assertSame(1, $this->fired);
    }

    private function countFiring(): void
    {
        ++$this->fired;
    }

    private static function createScope(): ResourceScope
    {
        return ResourceScope::forApp(new AppId('demo'));
    }
}
