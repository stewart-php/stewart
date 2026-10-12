<?php

declare(strict_types=1);

namespace Stewart\Support\Tests\Unit\Stream;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\WaitError;
use Stewart\Contracts\Exception\WaitException;
use Stewart\Contracts\Time\Duration;
use Stewart\Support\Stream\ComposedStream;
use Stewart\Support\Stream\OperatorStream;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Stream\PushSource;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;

use function Amp\async;

#[CoversClass(ComposedStream::class)]
#[CoversClass(WaitException::class)]
#[CoversClass(WaitError::class)]
final class WaitForNextTest extends TestCase
{
    use AssertsReason;

    private ManualTimers $timers;

    /** @var PushSource<int> */
    private PushSource $source;

    /** @var OperatorStream<int> */
    private OperatorStream $stream;

    protected function setUp(): void
    {
        $this->timers = new ManualTimers();
        $this->source = new PushSource();
        $this->stream = new OperatorStream($this->source, $this->timers);
    }

    public function testWaitEndsWithTheNextEventAndDetaches(): void
    {
        $outcome = async(fn(): ?int => $this->stream->waitForNext());
        EventLoopTicks::settle();

        self::assertSame(1, $this->source->countSubscribers());

        $this->source->push(7);
        $this->source->push(8);

        self::assertSame(7, $outcome->await());
        self::assertSame(0, $this->source->countSubscribers());
    }

    public function testFilteredWaitSkipsEventsThatDoNotMatch(): void
    {
        $outcome = async(fn(): ?int => $this->stream->filter(static fn(int $n): bool => $n > 5)->waitForNext());
        EventLoopTicks::settle();

        $this->source->push(3);
        $this->source->push(9);

        self::assertSame(9, $outcome->await());
    }

    public function testWaitEndsWithNullOnceItsTimeoutPasses(): void
    {
        $outcome = async(fn(): ?int => $this->stream->waitForNext(Duration::seconds(30)));
        EventLoopTicks::settle();

        $this->timers->delay(Duration::seconds(30));

        self::assertNull($outcome->await());
        self::assertSame(0, $this->source->countSubscribers());
        self::assertSame(0, $this->timers->countPendingTimers());
    }

    public function testStreamCompletingFirstAbandonsTheWait(): void
    {
        /** @var PushSource<string> $stop */
        $stop = new PushSource();
        $outcome = async(fn(): ?int => $this->stream->takeUntil(new OperatorStream($stop, $this->timers))->waitForNext());
        EventLoopTicks::settle();

        $stop->push('stop');

        $this->assertThrowsReason(WaitError::Abandoned, static fn() => $outcome->await());
        self::assertSame(0, $this->source->countSubscribers());
    }

    public function testStreamThatIsAlreadyCompleteAbandonsTheWaitAtOnce(): void
    {
        $this->assertThrowsReason(WaitError::Abandoned, fn() => $this->stream->take(0)->waitForNext());
    }
}
