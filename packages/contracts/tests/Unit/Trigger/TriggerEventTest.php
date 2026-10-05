<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Trigger;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\State\EventContext;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Trigger\TriggerEvent;

#[CoversClass(TriggerEvent::class)]
final class TriggerEventTest extends TestCase
{
    public function testPlatformFallsBackToTriggerKey(): void
    {
        self::assertSame('sun', new TriggerEvent(['platform' => 'sun'])->getPlatform());
        self::assertSame('time', new TriggerEvent(['trigger' => 'time'])->getPlatform());
        self::assertNull(new TriggerEvent([])->getPlatform());
    }

    public function testTriggerIdFallsBackToIndex(): void
    {
        self::assertSame('dusk', new TriggerEvent(['id' => 'dusk', 'idx' => '1'])->getTriggerId());
        self::assertSame('2', new TriggerEvent(['idx' => 2])->getTriggerId());
        self::assertNull(new TriggerEvent([])->getTriggerId());
    }

    public function testDataReadsAndFiredAtCopy(): void
    {
        $event = new TriggerEvent(['platform' => 'sun', 'event' => 'sunset'], new EventContext('ctx'));
        $firedAt = Instant::fromEpochMicroseconds(1_000);

        $stamped = $event->withFiredAt($firedAt);

        self::assertSame('sunset', $event->getValue('event'));
        self::assertNull($event->getValue('missing'));
        self::assertSame(['platform' => 'sun', 'event' => 'sunset'], $stamped->getTriggerData());
        self::assertSame($firedAt, $stamped->firedAt);
        self::assertSame('ctx', $stamped->context?->id);
        self::assertNull($event->firedAt);
    }
}
