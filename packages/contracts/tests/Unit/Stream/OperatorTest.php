<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Stream;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exception\TimeError;
use Stewart\Contracts\Stream\ComposedStream;
use Stewart\Contracts\Stream\DebounceOperator;
use Stewart\Contracts\Stream\Edge;
use Stewart\Contracts\Stream\FilterOperator;
use Stewart\Contracts\Stream\MapOperator;
use Stewart\Contracts\Stream\OperatorStream;
use Stewart\Contracts\Stream\PendingEmission;
use Stewart\Contracts\Stream\PendingEmissions;
use Stewart\Contracts\Stream\TakeOperator;
use Stewart\Contracts\Stream\TakeUntilOperator;
use Stewart\Contracts\Stream\ThrottleOperator;
use Stewart\Contracts\Stream\ThrottleWindow;
use Stewart\Contracts\Subscription;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Stream\PushSource;
use Stewart\Testing\Time\ManualTimers;

#[CoversClass(OperatorStream::class)]
#[CoversClass(ComposedStream::class)]
#[CoversClass(FilterOperator::class)]
#[CoversClass(MapOperator::class)]
#[CoversClass(DebounceOperator::class)]
#[CoversClass(ThrottleOperator::class)]
#[CoversClass(TakeOperator::class)]
#[CoversClass(TakeUntilOperator::class)]
#[CoversClass(PendingEmission::class)]
#[CoversClass(PendingEmissions::class)]
#[CoversClass(ThrottleWindow::class)]
#[CoversClass(Edge::class)]
final class OperatorTest extends TestCase
{
    use AssertsReason;

    private ManualTimers $timers;

    /** @var PushSource<mixed> */
    private PushSource $source;

    /** @var OperatorStream<mixed> */
    private OperatorStream $stream;

    /** @var list<mixed> */
    private array $received = [];

    protected function setUp(): void
    {
        $this->timers = new ManualTimers();
        $this->source = new PushSource();
        $this->stream = new OperatorStream($this->source, $this->timers);
        $this->received = [];
    }

    public function testNothingIsRegisteredUntilSubscribeIsCalled(): void
    {
        $this->stream->filter(static fn(): bool => true)->debounce(Duration::seconds(1));

        self::assertSame(0, $this->source->countSubscribers(), 'Building a chain must cost one object each and no registration.');
    }

    public function testFilterPassesOnlyMatchingEvents(): void
    {
        $this->listen($this->stream->filter(static fn(mixed $n): bool => \is_int($n) && $n > 2));

        $this->push(1, 2, 3, 4);

        self::assertSame([3, 4], $this->received);
    }

    public function testMapTransformsEachEvent(): void
    {
        $this->listen($this->stream->map(static fn(mixed $n): string => 'n' . (\is_int($n) ? $n : 0)));

        $this->push(1, 2);

        self::assertSame(['n1', 'n2'], $this->received);
    }

    public function testZeroWindowIsRefused(): void
    {
        $this->assertThrowsReason(TimeError::DurationNotPositive, fn() => $this->stream->throttle(Duration::zero()));
    }

    public function testDebounceEmitsLastEventWhenStreamGoesQuiet(): void
    {
        $this->listen($this->stream->debounce(Duration::milliseconds(300)));

        $this->push('a', 'b', 'c');
        self::assertSame([], $this->received, 'Trailing waits for the window.');

        $this->timers->delay(Duration::milliseconds(299));
        self::assertSame([], $this->received);

        $this->timers->delay(Duration::milliseconds(1));
        self::assertSame(['c'], $this->received);
    }

    public function testDebounceRestartsOnEveryEvent(): void
    {
        $this->listen($this->stream->debounce(Duration::milliseconds(300)));

        $this->push('a');
        $this->timers->delay(Duration::milliseconds(200));
        $this->push('b');
        $this->timers->delay(Duration::milliseconds(200));

        self::assertSame([], $this->received, '400ms have passed but never 300ms of quiet.');

        $this->timers->delay(Duration::milliseconds(100));
        self::assertSame(['b'], $this->received);
    }

    public function testLeadingDebounceEmitsOnlyFirstOfBurst(): void
    {
        $this->listen($this->stream->debounce(Duration::milliseconds(300), Edge::Leading));

        $this->push('a', 'b', 'c');
        self::assertSame(['a'], $this->received);

        $this->timers->delay(Duration::seconds(1));
        self::assertSame(['a'], $this->received, 'Leading never emits a trailing event.');

        $this->push('d');
        self::assertSame(['a', 'd'], $this->received, 'The window has closed, so a new burst leads again.');
    }

    public function testDebounceOnBothEdgesEmitsFirstAndLast(): void
    {
        $this->listen($this->stream->debounce(Duration::milliseconds(300), Edge::Both));

        $this->push('a', 'b', 'c');
        self::assertSame(['a'], $this->received);

        $this->timers->delay(Duration::milliseconds(300));
        self::assertSame(['a', 'c'], $this->received);
    }

    public function testDebounceOnBothEdgesEmitsOnceForASingleEvent(): void
    {
        $this->listen($this->stream->debounce(Duration::milliseconds(300), Edge::Both));

        $this->push('only');
        $this->timers->delay(Duration::seconds(1));

        self::assertSame(['only'], $this->received, 'Nothing was suppressed, so there is no trailing event to emit.');
    }

    public function testThrottleEmitsAtOnceThenGatesTheWindow(): void
    {
        $this->listen($this->stream->throttle(Duration::milliseconds(300)));

        $this->push('a', 'b', 'c');
        self::assertSame(['a'], $this->received);

        $this->timers->delay(Duration::milliseconds(300));
        self::assertSame(['a'], $this->received, 'Leading throttle drops what it suppressed.');

        $this->push('d');
        self::assertSame(['a', 'd'], $this->received);
    }

    public function testTrailingThrottleEmitsTheLastEventOfEachWindow(): void
    {
        $this->listen($this->stream->throttle(Duration::milliseconds(300), Edge::Trailing));

        $this->push('a', 'b');
        self::assertSame([], $this->received);

        $this->timers->delay(Duration::milliseconds(300));
        self::assertSame(['b'], $this->received);
    }

    public function testThrottleBothEdgesEmitsFirstThenLastSuppressed(): void
    {
        $this->listen($this->stream->throttle(Duration::milliseconds(300), Edge::Both));

        $this->push('a', 'b', 'c');
        self::assertSame(['a'], $this->received);

        $this->timers->delay(Duration::milliseconds(300));
        self::assertSame(['a', 'c'], $this->received);
    }

    public function testTrailingThrottleFlushesEachWindowInTurn(): void
    {
        $this->listen($this->stream->throttle(Duration::milliseconds(300), Edge::Trailing));

        $this->push('a', 'b');
        $this->timers->delay(Duration::milliseconds(300));
        self::assertSame(['b'], $this->received);

        $this->push('c', 'd');
        self::assertSame(['b'], $this->received, 'The flush opened the next window, so these are held.');

        $this->timers->delay(Duration::milliseconds(300));
        self::assertSame(['b', 'd'], $this->received);
    }

    public function testThrottleFlushOpensTheNextWindow(): void
    {
        $this->listen($this->stream->throttle(Duration::milliseconds(300), Edge::Both));

        $this->push('a', 'b');
        $this->timers->delay(Duration::milliseconds(300));
        self::assertSame(['a', 'b'], $this->received);

        $this->push('c');
        self::assertSame(['a', 'b'], $this->received, 'Inside the window a flush started, the next event is held rather than led.');

        $this->timers->delay(Duration::milliseconds(300));
        self::assertSame(['a', 'b', 'c'], $this->received);
    }

    public function testQuietThrottleWindowLeavesNoTimerBehind(): void
    {
        $this->listen($this->stream->throttle(Duration::milliseconds(300), Edge::Both));

        $this->push('a');
        self::assertSame(1, $this->timers->countPendingTimers());

        $this->timers->delay(Duration::milliseconds(300));
        self::assertSame(0, $this->timers->countPendingTimers(), 'Nothing was held, so the window closes without opening another.');
    }

    public function testThrottleOpensWindowWhenHandlerThrows(): void
    {
        $this->listenAndThrow($this->stream->throttle(Duration::milliseconds(300)));

        $this->pushExpectingFailure('a');
        $this->push('b');

        self::assertSame(['a'], $this->received, 'A failing handler must not leave the gate open.');
    }

    public function testDebounceArmsWhenHandlerThrows(): void
    {
        $this->listenAndThrow($this->stream->debounce(Duration::milliseconds(300), Edge::Leading));

        $this->pushExpectingFailure('a');
        $this->push('b');

        self::assertSame(['a'], $this->received, 'A failing handler must not leave the gate open.');
    }

    public function testLeadingUnsubscribeLeavesNoThrottleTimer(): void
    {
        $this->listenAndUnsubscribe($this->stream->throttle(Duration::milliseconds(300)));

        $this->push('a');

        self::assertSame(0, $this->timers->countPendingTimers());
    }

    public function testLeadingUnsubscribeLeavesNoDebounceTimer(): void
    {
        $this->listenAndUnsubscribe($this->stream->debounce(Duration::milliseconds(300), Edge::Leading));

        $this->push('a');

        self::assertSame(0, $this->timers->countPendingTimers());
    }

    public function testTakeBeforeDebounceEmitsAfterWindow(): void
    {
        $this->listen($this->stream->take(1)->debounce(Duration::milliseconds(300)));

        $this->push('a', 'b');

        self::assertSame([], $this->received);
        self::assertSame(1, $this->source->countSubscribers(), 'The debounce still holds the taken event.');

        $this->timers->delay(Duration::milliseconds(300));

        self::assertSame(['a'], $this->received);
        self::assertSame(0, $this->source->countSubscribers());
        self::assertSame(0, $this->timers->countPendingTimers());
    }

    public function testTakeBeforeThrottleFlushesHeldEvent(): void
    {
        $this->listen($this->stream->take(2)->throttle(Duration::milliseconds(300), Edge::Both));

        $this->push('a', 'b', 'c');
        self::assertSame(['a'], $this->received);

        $this->timers->delay(Duration::milliseconds(300));

        self::assertSame(['a', 'b'], $this->received);
        self::assertSame(0, $this->source->countSubscribers());
        self::assertSame(0, $this->timers->countPendingTimers(), 'The window reopened by the flush closes with the scope.');
    }

    public function testTakeWaitsForChainedWindowsToFlush(): void
    {
        $this->listen($this->stream->take(1)->debounce(Duration::milliseconds(300))->throttle(Duration::milliseconds(300), Edge::Trailing));

        $this->push('a');
        $this->timers->delay(Duration::milliseconds(300));

        self::assertSame([], $this->received);
        self::assertSame(1, $this->source->countSubscribers(), 'The throttle now holds what the debounce let through.');

        $this->timers->delay(Duration::milliseconds(300));

        self::assertSame(['a'], $this->received);
        self::assertSame(0, $this->source->countSubscribers());
        self::assertSame(0, $this->timers->countPendingTimers());
    }

    public function testTakeBeforeDebounceClosesWhenHandlerThrows(): void
    {
        $subscription = $this->listenAndThrow($this->stream->take(1)->debounce(Duration::milliseconds(300)));
        $this->push('a');

        try {
            $this->timers->delay(Duration::milliseconds(300));
            self::fail('The handler failure must reach the caller.');
        } catch (RuntimeException $e) {
            self::assertSame('handler blew up', $e->getMessage());
        }

        self::assertFalse($subscription->isActive());
        self::assertSame(0, $this->source->countSubscribers());
    }

    public function testTakeAfterDebounceClosesWhenHandlerThrows(): void
    {
        $subscription = $this->listenAndThrow($this->stream->debounce(Duration::milliseconds(300))->take(1));
        $this->push('a');

        try {
            $this->timers->delay(Duration::milliseconds(300));
            self::fail('The handler failure must reach the caller.');
        } catch (RuntimeException $e) {
            self::assertSame('handler blew up', $e->getMessage());
        }

        self::assertFalse($subscription->isActive());
        self::assertSame(0, $this->source->countSubscribers());
    }

    public function testUnsubscribeStopsTakeBeforeDebounceAtOnce(): void
    {
        $subscription = $this->listen($this->stream->take(1)->debounce(Duration::milliseconds(300)));
        $this->push('a');

        $subscription->unsubscribe();

        self::assertSame(0, $this->timers->countPendingTimers());

        $this->timers->delay(Duration::seconds(1));
        self::assertSame([], $this->received);
    }

    public function testTakeStopsAfterTheGivenCountAndUnsubscribes(): void
    {
        $this->listen($this->stream->take(2));

        $this->push('a', 'b', 'c');

        self::assertSame(['a', 'b'], $this->received);
        self::assertSame(0, $this->source->countSubscribers(), 'take() must release the upstream registration, not just ignore events.');
    }

    public function testTakeOfNothingSubscribesToNothing(): void
    {
        $subscription = $this->listen($this->stream->take(0));

        $this->push('a');

        self::assertSame([], $this->received);
        self::assertFalse($subscription->isActive());
        self::assertSame(0, $this->source->countAttachments(), 'take(0) must not register upstream only to cancel it.');
    }

    public function testTakeUnsubscribesEvenWhenItsLastHandlerThrows(): void
    {
        $subscription = $this->stream->take(1)->subscribe(static function (): void {
            throw new RuntimeException('handler blew up');
        });

        try {
            $this->push('a');
            self::fail('The handler failure must reach the caller.');
        } catch (RuntimeException $e) {
            self::assertSame('handler blew up', $e->getMessage());
        }

        self::assertFalse($subscription->isActive());
        self::assertSame(0, $this->source->countSubscribers(), 'A completed take() leaves nothing registered upstream.');
    }

    public function testTakeUntilStopsWhenTheNotifierFires(): void
    {
        /** @var PushSource<mixed> $notifierSource */
        $notifierSource = new PushSource();
        $notifier = new OperatorStream($notifierSource, $this->timers);

        $this->listen($this->stream->takeUntil($notifier));

        $this->push('a');
        self::assertSame(['a'], $this->received);

        $notifierSource->push('stop');

        $this->push('b');
        self::assertSame(['a'], $this->received);
    }

    public function testTakeUntilReleasesTheNotifierToo(): void
    {
        /** @var PushSource<mixed> $notifierSource */
        $notifierSource = new PushSource();
        $notifier = new OperatorStream($notifierSource, $this->timers);

        $subscription = $this->listen($this->stream->takeUntil($notifier));

        self::assertSame(1, $notifierSource->countSubscribers());

        $subscription->unsubscribe();

        self::assertSame(0, $notifierSource->countSubscribers(), 'Otherwise the notifier outlives what it was watching for and leaks a registration in the broker.');
        self::assertSame(0, $this->source->countSubscribers());
    }

    public function testUnsubscribingCancelsAPendingDebounce(): void
    {
        $subscription = $this->listen($this->stream->debounce(Duration::milliseconds(300)));

        $this->push('a');
        self::assertSame(1, $this->timers->countPendingTimers());

        $subscription->unsubscribe();

        self::assertSame(0, $this->timers->countPendingTimers(), 'A timer outliving its subscription would fire into a disposed app.');

        $this->timers->delay(Duration::seconds(1));
        self::assertSame([], $this->received);
    }

    public function testSubscriptionsDoNotShareOperatorState(): void
    {
        $counted = $this->stream->take(1);

        $first = [];
        $second = [];
        $counted->subscribe(static function (mixed $event) use (&$first): void {
            $first[] = $event;
        });
        $counted->subscribe(static function (mixed $event) use (&$second): void {
            $second[] = $event;
        });

        $this->push('a');

        self::assertSame(['a'], $first);
        self::assertSame(['a'], $second, 'Operator state belongs to a subscription, not to the stream it was built from.');
    }

    public function testOperatorsComposeInOrder(): void
    {
        $this->listen(
            $this->stream
                ->map(static fn(mixed $n): int => (\is_int($n) ? $n : 0) + 1)
                ->filter(static fn(mixed $n): bool => \is_int($n) && $n % 2 === 0),
        );

        $this->push(1, 2, 3);

        self::assertSame([2, 4], $this->received, 'Filtering before mapping would have let 2 through and dropped the rest.');
    }

    /** @param EventStream<mixed> $stream */
    private function listen(EventStream $stream): Subscription
    {
        return $stream->subscribe(function (mixed $event): void {
            $this->received[] = $event;
        });
    }

    /** @param EventStream<mixed> $stream */
    private function listenAndThrow(EventStream $stream): Subscription
    {
        return $stream->subscribe(function (mixed $event): void {
            $this->received[] = $event;

            throw new RuntimeException('handler blew up');
        });
    }

    /** @param EventStream<mixed> $stream */
    private function listenAndUnsubscribe(EventStream $stream): void
    {
        $subscription = null;
        $subscription = $stream->subscribe(function (mixed $event) use (&$subscription): void {
            $this->received[] = $event;
            $subscription?->unsubscribe();
        });
    }

    private function pushExpectingFailure(mixed $event): void
    {
        try {
            $this->push($event);
            self::fail('The handler failure must reach the caller.');
        } catch (RuntimeException $e) {
            self::assertSame('handler blew up', $e->getMessage());
        }
    }

    private function push(mixed ...$events): void
    {
        foreach ($events as $event) {
            $this->source->push($event);
        }
    }
}
