<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Dispatch;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\WaitError;
use Stewart\Contracts\Exception\WaitException;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Dispatch\SubscriptionQueue;
use Stewart\Runtime\Tests\Fixtures\Dispatch\QueuedStreamFixture;
use Stewart\Testing\Time\EventLoopTicks;

use function Amp\async;

#[CoversClass(SubscriptionQueue::class)]
final class QueuedWaitForNextTest extends TestCase
{
    private QueuedStreamFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new QueuedStreamFixture();
    }

    public function testDispatchedChangeEndsTheWait(): void
    {
        $outcome = async(fn(): ?StateChange => $this->fixture->watchStateChanges('light.hall')->waitForNext(Duration::seconds(30)));
        EventLoopTicks::settle();

        $this->fixture->dispatchStateChange('light.hall', 'off', 'on');
        $this->fixture->drainQueues();

        $change = $outcome->await();
        self::assertInstanceOf(StateChange::class, $change);
        self::assertSame('on', $change->to?->state);
        self::assertSame(0, $this->fixture->countSubscriptions());
    }

    public function testPausedAppsWaitTimesOut(): void
    {
        $outcome = async(fn(): ?StateChange => $this->fixture->watchStateChanges('light.hall')->waitForNext(Duration::seconds(30)));
        EventLoopTicks::settle();

        $this->fixture->pauseScope();
        $this->fixture->dispatchStateChange('light.hall', 'off', 'on');
        $this->fixture->drainQueues();
        $this->fixture->advanceTime(Duration::seconds(30));

        self::assertNull($outcome->await());
    }

    public function testCancellingEndsAWaitInsideAHandler(): void
    {
        $this->fixture->watchStateChanges('binary_sensor.door')->subscribe(function (): void {
            $this->fixture->watchStateChanges('lock.front')->waitForNext();
        });

        $this->fixture->dispatchStateChange('binary_sensor.door', 'off', 'on');
        $this->fixture->drainQueues();
        $this->fixture->cancelSubscriptions();
        $this->fixture->drainQueues();

        self::assertCount(1, $this->fixture->listener->failures);
        self::assertInstanceOf(WaitException::class, $this->fixture->listener->failures[0]);
        self::assertSame(WaitError::Abandoned, $this->fixture->listener->failures[0]->reason);
        self::assertSame(0, $this->fixture->countSubscriptions());
    }
}
