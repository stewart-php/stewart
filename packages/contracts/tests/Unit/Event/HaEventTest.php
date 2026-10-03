<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Event\EventOrigin;
use Stewart\Contracts\Event\HaEvent;

#[CoversClass(HaEvent::class)]
final class HaEventTest extends TestCase
{
    public function testDataIsReadThroughValue(): void
    {
        $event = new HaEvent('zha_event', ['command' => 'toggle', 'args' => [1, 2]]);

        self::assertSame('toggle', $event->getValue('command'));
        self::assertSame([1, 2], $event->getValue('args'));
        self::assertNull($event->getValue('missing'));
    }

    public function testDefaultsAreTheQuietOnes(): void
    {
        $event = new HaEvent('stewart_demo');

        self::assertSame([], $event->data);
        self::assertSame(EventOrigin::Local, $event->origin);
        self::assertNull($event->firedAt);
        self::assertNull($event->context);
    }
}
