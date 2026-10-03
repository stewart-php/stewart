<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Selector\Selector;
use Stewart\Runtime\Model\SubscriptionKind;

#[CoversClass(SubscriptionKind::class)]
final class SubscriptionKindTest extends TestCase
{
    public function testMqttKindAcceptsOnlyMqttFilters(): void
    {
        self::assertTrue(SubscriptionKind::Mqtt->acceptsSelector(Selector::mqttFilter('home/#')));
        self::assertFalse(SubscriptionKind::Mqtt->acceptsSelector(Selector::glob('home/*')));
    }

    public function testOtherKindsRejectMqttFilters(): void
    {
        self::assertTrue(SubscriptionKind::Topic->acceptsSelector(Selector::glob('home.*')));
        self::assertFalse(SubscriptionKind::Topic->acceptsSelector(Selector::mqttFilter('home/#')));
    }

    public function testMqttSubscriptionsAreBrokerRouted(): void
    {
        self::assertTrue(SubscriptionKind::Mqtt->isBrokerRouted());
    }
}
