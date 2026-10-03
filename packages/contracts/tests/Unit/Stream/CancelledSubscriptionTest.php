<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Stream;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Stream\CancelledSubscription;

#[CoversClass(CancelledSubscription::class)]
final class CancelledSubscriptionTest extends TestCase
{
    public function testEachCancelledSubscriptionHasOwnId(): void
    {
        self::assertNotSame(CancelledSubscription::createUnique()->getId(), CancelledSubscription::createUnique()->getId());
    }

    public function testCancelledSubscriptionIsInactive(): void
    {
        self::assertFalse(CancelledSubscription::createUnique()->isActive());
    }
}
