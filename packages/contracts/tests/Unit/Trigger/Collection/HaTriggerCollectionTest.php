<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Trigger\Collection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Trigger\Collection\HaTriggerCollection;
use Stewart\Contracts\Trigger\HaTrigger;

#[CoversClass(HaTriggerCollection::class)]
final class HaTriggerCollectionTest extends TestCase
{
    public function testFromTriggersKeepsOrder(): void
    {
        $sunrise = HaTrigger::onSunrise();
        $sunset = HaTrigger::onSunset();

        self::assertSame([$sunrise, $sunset], HaTriggerCollection::fromTriggers([$sunrise, $sunset])->listValues());
    }
}
