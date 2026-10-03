<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Dispatch;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\StateChangeStream;
use Stewart\Contracts\Stream\Edge;
use Stewart\Contracts\Stream\PendingEmission;
use Stewart\Contracts\Stream\PendingEmissions;
use Stewart\Contracts\Stream\ThrottleWindow;
use Stewart\Contracts\Subscription;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Dispatch\SubscriptionQueue;
use Stewart\Runtime\Tests\Fixtures\Dispatch\QueuedStreamFixture;
use Stewart\Testing\Async\Latch;

#[CoversClass(SubscriptionQueue::class)]
#[CoversClass(PendingEmission::class)]
#[CoversClass(PendingEmissions::class)]
#[CoversClass(ThrottleWindow::class)]
final class QueuedOperatorTest extends TestCase
{
    private const string BUSY = 'sensor.busy';

    private const string WATCHED = 'sensor.watched';

    private QueuedStreamFixture $streams;

    private Latch $busyHandler;

    /** @var list<string> */
    private array $received = [];

    protected function setUp(): void
    {
        $this->streams = new QueuedStreamFixture();
        $this->busyHandler = new Latch();
        $this->received = [];
    }

    protected function tearDown(): void
    {
        $this->busyHandler->open();
        $this->streams->drainQueues();
    }

    public function testChangedToSkipsHoldBrokenWhileQueued(): void
    {
        $this->subscribeBlockingOnBusy($this->streams->watchStateChanges('sensor.*')->whenChangedTo('off', Duration::minutes(1)));

        $this->dispatchAndDrain(self::BUSY, 'on', 'off');
        $this->advanceAndDrain(Duration::seconds(30));
        $this->dispatchAndDrain(self::WATCHED, 'on', 'off');
        $this->advanceAndDrain(Duration::seconds(30));

        self::assertSame(['sensor.busy=off'], $this->received, 'The busy handler is now suspended.');

        $this->streams->dispatchStateChange(self::WATCHED, 'off', 'on');
        $this->advanceAndDrain(Duration::seconds(30));
        $this->releaseBusyHandler();

        self::assertSame(['sensor.busy=off'], $this->received, 'The departure was queued before the window elapsed.');
    }

    public function testStableForSkipsHoldBrokenWhileQueued(): void
    {
        $this->subscribeBlockingOnBusy($this->streams->watchStateChanges('sensor.*')->whenStableFor(Duration::minutes(1)));

        $this->dispatchAndDrain(self::BUSY, '1', '2');
        $this->advanceAndDrain(Duration::seconds(30));
        $this->dispatchAndDrain(self::WATCHED, '1', '2');
        $this->advanceAndDrain(Duration::seconds(30));
        $this->streams->dispatchStateChange(self::WATCHED, '2', '3');
        $this->advanceAndDrain(Duration::seconds(30));
        $this->releaseBusyHandler();

        self::assertSame(['sensor.busy=2'], $this->received, 'The watched sensor left 2 before its window elapsed.');

        $this->advanceAndDrain(Duration::minutes(1));

        self::assertSame(['sensor.busy=2', 'sensor.watched=3'], $this->received);
    }

    public function testDebounceRestartsForEventQueuedInWindow(): void
    {
        $this->subscribeBlockingOnBusy($this->streams->watchStateChanges('sensor.*')->debounce(Duration::minutes(1)));

        $this->dispatchAndDrain(self::BUSY, '1', '2');
        $this->advanceAndDrain(Duration::seconds(30));
        $this->dispatchAndDrain(self::WATCHED, '1', '2');
        $this->advanceAndDrain(Duration::seconds(30));
        $this->streams->dispatchStateChange(self::WATCHED, '2', '3');
        $this->advanceAndDrain(Duration::seconds(30));
        $this->releaseBusyHandler();

        self::assertSame(['sensor.busy=2'], $this->received, 'The second change arrived inside the window and restarts it.');

        $this->advanceAndDrain(Duration::minutes(1));

        self::assertSame(['sensor.busy=2', 'sensor.watched=3'], $this->received);
    }

    public function testDebounceDoesNotLeadForEventQueuedInWindow(): void
    {
        $this->subscribeBlockingOnBusy($this->streams->watchStateChanges('sensor.*')->debounce(Duration::minutes(1), Edge::Both));

        $this->dispatchAndDrain(self::WATCHED, '1', '2');
        $this->advanceAndDrain(Duration::seconds(30));
        $this->dispatchAndDrain(self::BUSY, '1', '2');
        $this->streams->dispatchStateChange(self::WATCHED, '2', '3');
        $this->advanceAndDrain(Duration::seconds(30));
        $this->releaseBusyHandler();

        self::assertSame(['sensor.watched=2', 'sensor.busy=2'], $this->received, 'The queued change is a trailing event, not a new lead.');

        $this->advanceAndDrain(Duration::minutes(1));

        self::assertSame(['sensor.watched=2', 'sensor.busy=2', 'sensor.watched=3'], $this->received);
    }

    public function testThrottleDropsEventQueuedInsideWindow(): void
    {
        $this->subscribeBlockingOnBusy($this->streams->watchStateChanges('sensor.*')->throttle(Duration::minutes(1)));

        $this->dispatchAndDrain(self::WATCHED, '1', '2');
        $this->advanceAndDrain(Duration::seconds(30));
        $this->dispatchAndDrain(self::BUSY, '1', '2');
        $this->streams->dispatchStateChange(self::WATCHED, '2', '3');
        $this->advanceAndDrain(Duration::seconds(30));
        $this->releaseBusyHandler();

        self::assertSame(['sensor.watched=2', 'sensor.busy=2'], $this->received, 'The queued change arrived inside the window.');
    }

    public function testThrottleFlushesEventQueuedInsideWindow(): void
    {
        $this->subscribeBlockingOnBusy($this->streams->watchStateChanges('sensor.*')->throttle(Duration::minutes(1), Edge::Trailing));

        $this->dispatchAndDrain(self::BUSY, '1', '2');
        $this->advanceAndDrain(Duration::seconds(10));
        $this->dispatchAndDrain(self::WATCHED, '1', '2');
        $this->advanceAndDrain(Duration::seconds(50));

        self::assertSame(['sensor.busy=2'], $this->received, 'The busy handler is now suspended.');

        $this->streams->dispatchStateChange(self::WATCHED, '2', '3');
        $this->advanceAndDrain(Duration::seconds(10));
        $this->releaseBusyHandler();

        self::assertSame(['sensor.busy=2', 'sensor.watched=3'], $this->received, 'The window ends with the last change it saw.');
    }

    public function testTakeBeforeDebounceCancelsAfterEmission(): void
    {
        $subscription = $this->subscribeBlockingOnBusy($this->streams->watchStateChanges(self::WATCHED)->take(1)->debounce(Duration::minutes(1)));

        $this->dispatchAndDrain(self::WATCHED, 'on', 'off');

        self::assertSame(1, $this->streams->countSubscriptions(), 'The debounce still holds the taken event.');

        $this->advanceAndDrain(Duration::minutes(1));

        self::assertSame(['sensor.watched=off'], $this->received);
        self::assertSame(0, $this->streams->countSubscriptions());
        self::assertSame([$subscription->getId()], $this->streams->listener->cancelled);
    }

    public function testThrowingHandlerKeepsThrottleWindowOpen(): void
    {
        $this->streams->watchStateChanges(self::WATCHED)->throttle(Duration::minutes(1))->subscribe(function (StateChange $change): void {
            $this->received[] = $change->entityId . '=' . $change->to?->state;

            throw new RuntimeException('handler blew up');
        });

        $this->dispatchAndDrain(self::WATCHED, '1', '2');
        $this->dispatchAndDrain(self::WATCHED, '2', '3');

        self::assertSame(['sensor.watched=2'], $this->received);
        self::assertCount(1, $this->streams->listener->failures);
    }

    private function subscribeBlockingOnBusy(StateChangeStream $stream): Subscription
    {
        $busyHandler = $this->busyHandler;

        return $stream->subscribe(function (StateChange $change) use ($busyHandler): void {
            $this->received[] = $change->entityId . '=' . $change->to?->state;

            if ($change->entityId->value === self::BUSY) {
                $busyHandler->waitUntilOpen();
            }
        });
    }

    private function dispatchAndDrain(string $entityId, string $from, string $to): void
    {
        $this->streams->dispatchStateChange($entityId, $from, $to);
        $this->streams->drainQueues();
    }

    private function advanceAndDrain(Duration $by): void
    {
        $this->streams->advanceTime($by);
        $this->streams->drainQueues();
    }

    private function releaseBusyHandler(): void
    {
        $this->busyHandler->open();
        $this->streams->drainQueues();
    }
}
