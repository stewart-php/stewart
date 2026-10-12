<?php

declare(strict_types=1);

namespace Stewart\Testing\Tests\Unit\Stream;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stewart\Contracts\Stream\OperatorStream;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\Async\HandlerFibers;
use Stewart\Testing\Stream\PushSource;
use Stewart\Testing\Stream\PushSubscription;
use Stewart\Testing\Time\ManualTimers;

#[CoversClass(PushSource::class)]
#[CoversClass(PushSubscription::class)]
#[CoversClass(HandlerFibers::class)]
final class PushSourceTest extends TestCase
{
    private ManualTimers $timers;

    /** @var PushSource<string> */
    private PushSource $doors;

    /** @var PushSource<string> */
    private PushSource $locks;

    /** @var list<string> */
    private array $seen = [];

    protected function setUp(): void
    {
        $this->timers = new ManualTimers();
        $this->doors = new PushSource($this->timers->handlerFibers);
        $this->locks = new PushSource($this->timers->handlerFibers);
        $this->seen = [];
    }

    public function testHandlerThatWaitsDoesNotBlockThePush(): void
    {
        $this->watch($this->doors)->subscribe(function (string $door): void {
            $this->seen[] = $door . ' then ' . $this->watch($this->locks)->waitForNext();
        });

        $this->doors->push('open');
        self::assertSame([], $this->seen, 'The handler is suspended in its wait.');

        $this->locks->push('locked');
        self::assertSame(['open then locked'], $this->seen);
    }

    public function testFailureOfAHandlerThatDidNotWaitComesOutOfThePush(): void
    {
        $failure = new RuntimeException('handler broke');
        $this->watch($this->doors)->subscribe(static function () use ($failure): void {
            throw $failure;
        });

        $this->assertRethrows($failure, fn() => $this->doors->push('open'));
    }

    public function testFailureAfterAWaitIsRethrownLater(): void
    {
        $failure = new RuntimeException('failed after waiting');
        $this->watch($this->doors)->subscribe(function () use ($failure): void {
            $this->watch($this->locks)->waitForNext();

            throw $failure;
        });

        $this->doors->push('open');
        $this->locks->push('locked');

        $this->assertRethrows($failure, fn() => $this->timers->handlerFibers->rethrowFailures());
    }

    public function testDebouncedHandlerThatWaitsDoesNotBlockTime(): void
    {
        $this->watch($this->doors)->debounce(Duration::seconds(5))->subscribe(function (string $door): void {
            $this->seen[] = $door . ' then ' . ($this->watch($this->locks)->waitForNext(Duration::minutes(1)) ?? 'nothing');
        });

        $this->doors->push('open');
        $this->timers->delay(Duration::seconds(5));
        $this->timers->delay(Duration::minutes(1));

        self::assertSame(['open then nothing'], $this->seen);
    }

    public function testHeldDeliveriesRunOnRelease(): void
    {
        $this->watch($this->doors)->subscribe($this->record(...));

        $this->timers->handlerFibers->holdDeliveries();
        $this->doors->push('open');
        $this->doors->push('closed');
        self::assertSame([], $this->seen);

        $this->timers->handlerFibers->releaseDeliveries();
        self::assertSame(['open', 'closed'], $this->seen);
    }

    public function testCancelledAttachmentsReceiveNothing(): void
    {
        $subscription = $this->watch($this->doors)->subscribe($this->record(...));

        $this->doors->cancelAttachments();
        $this->doors->push('open');

        self::assertSame([], $this->seen);
        self::assertSame(0, $this->doors->countSubscribers());
        self::assertFalse($subscription->isActive());
    }

    /**
     * @param PushSource<string> $source
     * @return OperatorStream<string>
     */
    private function watch(PushSource $source): OperatorStream
    {
        return new OperatorStream($source, $this->timers);
    }

    /** @param Closure(): void $action */
    private function assertRethrows(RuntimeException $failure, Closure $action): void
    {
        try {
            $action();
            self::fail('The handler failure should be rethrown.');
        } catch (RuntimeException $e) {
            self::assertSame($failure, $e);
        }
    }

    private function record(string $event): void
    {
        $this->seen[] = $event;
    }
}
