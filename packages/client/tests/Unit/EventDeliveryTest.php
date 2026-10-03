<?php

declare(strict_types=1);

namespace Stewart\Client\Tests\Unit;

use Amp\DeferredFuture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Client\Connection\EnqueueOutcome;
use Stewart\Client\Connection\EventDelivery;
use Stewart\Client\Exception\HaClientError;
use Stewart\Client\Exception\HaClientException;
use Stewart\Testing\Exception\AssertsReason;
use Throwable;

use function Amp\async;
use function Amp\delay;

#[CoversClass(EventDelivery::class)]
final class EventDeliveryTest extends TestCase
{
    use AssertsReason;

    public function testEventBeyondTheLimitIsRefusedAsFull(): void
    {
        $delivery = new EventDelivery(1, new NullLogger());

        self::assertSame(EnqueueOutcome::Accepted, $delivery->enqueue(static function (): void {}, []));
        self::assertSame(EnqueueOutcome::Full, $delivery->enqueue(static function (): void {}, []));
    }

    public function testFlushReturnsOnceEveryEarlierEventWasDelivered(): void
    {
        $delivery = new EventDelivery(10, new NullLogger());
        $seen = [];

        foreach ([1, 2] as $sequence) {
            $delivery->enqueue(static function (array $payload) use (&$seen): void {
                $seen[] = $payload['sequence'];
            }, ['sequence' => $sequence]);
        }

        $delivery->flush();

        self::assertSame([1, 2], $seen);
    }

    public function testEventCallbackCannotWaitForItsOwnQueue(): void
    {
        $delivery = new EventDelivery(10, new NullLogger());
        $refused = null;

        $delivery->enqueue(static function () use ($delivery, &$refused): void {
            try {
                $delivery->flush();
            } catch (Throwable $e) {
                $refused = $e;
            }
        }, []);

        $delivery->flush();

        self::assertInstanceOf(HaClientException::class, $refused);
        self::assertSame(HaClientError::EventQueueReentered, $refused->reason);
    }

    public function testClosingWhileAFlushWaitsFailsTheFlush(): void
    {
        $delivery = new EventDelivery(10, new NullLogger());
        $gate = new DeferredFuture();
        $delivery->enqueue(static function () use ($gate): void {
            $gate->getFuture()->await();
        }, []);
        delay(0);

        async($delivery->close(...))->ignore();

        try {
            $this->assertThrowsReason(HaClientError::ConnectionDropped, fn() => $delivery->flush());
        } finally {
            $gate->complete();
        }
    }

    public function testFlushAfterCloseFails(): void
    {
        $delivery = new EventDelivery(10, new NullLogger());
        $delivery->close();

        $this->assertThrowsReason(HaClientError::ConnectionDropped, fn() => $delivery->flush());
    }

    public function testNothingQueuedIsDeliveredOnceClosed(): void
    {
        $delivery = new EventDelivery(10, new NullLogger());
        $delivered = false;

        $delivery->enqueue(static function () use (&$delivered): void {
            $delivered = true;
        }, []);
        $delivery->close();
        delay(0);

        self::assertFalse($delivered);
        self::assertSame(EnqueueOutcome::Closed, $delivery->enqueue(static function (): void {}, []));
    }
}
