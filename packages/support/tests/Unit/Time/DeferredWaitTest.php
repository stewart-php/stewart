<?php

declare(strict_types=1);

namespace Stewart\Support\Tests\Unit\Time;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stewart\Support\Time\DeferredWait;

#[CoversClass(DeferredWait::class)]
final class DeferredWaitTest extends TestCase
{
    public function testFirstResolutionWins(): void
    {
        /** @var DeferredWait<string> $wait */
        $wait = new DeferredWait();

        $wait->resolve('first');
        $wait->resolve('second');
        $wait->fail(new RuntimeException('too late'));
        $wait->expire();

        self::assertSame('first', $wait->awaitOutcome());
    }

    public function testFailureIsThrownFromTheAwait(): void
    {
        $failure = new RuntimeException('stream closed');
        /** @var DeferredWait<string> $wait */
        $wait = new DeferredWait();

        $wait->fail($failure);
        $wait->resolve('too late');

        try {
            $wait->awaitOutcome();
            self::fail('The failure should be thrown.');
        } catch (RuntimeException $e) {
            self::assertSame($failure, $e);
        }
    }

    public function testExpiredWaitEndsWithNull(): void
    {
        /** @var DeferredWait<string> $wait */
        $wait = new DeferredWait();

        $wait->expire();

        self::assertNull($wait->awaitOutcome());
    }
}
