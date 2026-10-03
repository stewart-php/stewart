<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Config\BackoffPolicy;

#[CoversClass(BackoffPolicy::class)]
final class BackoffPolicyTest extends TestCase
{
    public function testDelayDoublesPerAttempt(): void
    {
        $policy = new BackoffPolicy(Duration::seconds(1.0), Duration::seconds(60.0));

        self::assertSame(1.0, $policy->delayFor(1)->toSeconds());
        self::assertSame(2.0, $policy->delayFor(2)->toSeconds());
        self::assertSame(4.0, $policy->delayFor(3)->toSeconds());
        self::assertSame(8.0, $policy->delayFor(4)->toSeconds());
    }

    public function testDelayStopsAtTheCap(): void
    {
        $policy = new BackoffPolicy(Duration::seconds(1.0), Duration::seconds(10.0));

        self::assertSame(8.0, $policy->delayFor(4)->toSeconds());
        self::assertSame(10.0, $policy->delayFor(5)->toSeconds());
        self::assertSame(10.0, $policy->delayFor(50)->toSeconds(), 'A long outage must not overflow into an absurd delay.');
    }

    public function testThereIsNothingToWaitForBeforeTheFirstRetry(): void
    {
        $policy = new BackoffPolicy(Duration::seconds(1.0), Duration::seconds(60.0));

        self::assertSame(0.0, $policy->delayFor(0)->toSeconds());
        self::assertSame(0.0, $policy->delayFor(-1)->toSeconds());
    }

    public function testInitialDelayBelowASecondStillDoubles(): void
    {
        $policy = new BackoffPolicy(Duration::seconds(0.25), Duration::seconds(60.0));

        self::assertSame(0.25, $policy->delayFor(1)->toSeconds());
        self::assertSame(0.5, $policy->delayFor(2)->toSeconds());
    }

    public function testSumDoublesThenStaysAtTheCap(): void
    {
        $policy = new BackoffPolicy(Duration::seconds(1.0), Duration::seconds(30.0));

        self::assertEquals(Duration::seconds(31), $policy->sumDelaysUpTo(5));
        self::assertEquals(Duration::seconds(91), $policy->sumDelaysUpTo(7));
    }

    public function testSumOfNoAttemptsIsZero(): void
    {
        $policy = new BackoffPolicy(Duration::seconds(1.0), Duration::seconds(30.0));

        self::assertEquals(Duration::zero(), $policy->sumDelaysUpTo(0));
        self::assertEquals(Duration::zero(), $policy->sumDelaysUpTo(-3));
    }

    public function testHugeAttemptCountDoesNotOverflow(): void
    {
        $policy = new BackoffPolicy(Duration::seconds(1.0), Duration::hours(1));

        self::assertSame(\PHP_INT_MAX, $policy->sumDelaysUpTo(\PHP_INT_MAX)->toMicroseconds());
    }
}
