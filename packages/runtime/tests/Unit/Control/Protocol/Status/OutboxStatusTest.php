<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Control\Protocol\Status;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Control\Protocol\Status\OutboxStatus;

#[CoversClass(OutboxStatus::class)]
final class OutboxStatusTest extends TestCase
{
    public function testPlusSumsCountersAndKeepsLargestBatch(): void
    {
        $sum = new OutboxStatus(1, 2, 3, 4, 50)->plus(new OutboxStatus(10, 20, 30, 40, 5));

        self::assertEquals(new OutboxStatus(11, 22, 33, 44, 50), $sum);
    }

    public function testWithoutQueuedKeepsEveryOtherCounter(): void
    {
        self::assertEquals(new OutboxStatus(0, 2, 3, 4, 5), new OutboxStatus(1, 2, 3, 4, 5)->withoutQueued());
    }

    public function testZeroIsTheIdentityOfPlus(): void
    {
        $status = new OutboxStatus(1, 2, 3, 4, 5);

        self::assertEquals($status, OutboxStatus::zero()->plus($status));
    }
}
