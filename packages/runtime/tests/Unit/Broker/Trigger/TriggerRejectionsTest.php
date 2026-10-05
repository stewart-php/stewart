<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker\Trigger;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Trigger\HaTrigger;
use Stewart\Contracts\Trigger\TriggerSpec;
use Stewart\Runtime\Broker\BrokerSubscription;
use Stewart\Runtime\Broker\SubscriptionRegistry;
use Stewart\Runtime\Broker\Trigger\TriggerRejections;
use Stewart\Runtime\Broker\Trigger\TriggerSubscribers;
use Stewart\Runtime\Broker\WorkerSlotRegistry;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Runtime\Tests\Fixtures\Broker\TriggerSubscriptionFixture;

#[CoversClass(TriggerRejections::class)]
final class TriggerRejectionsTest extends TestCase
{
    public function testRejectionRemovesEverySubscriberOfThatSpecOnly(): void
    {
        $session = new FakeHaSession();
        $registry = new SubscriptionRegistry([new TriggerSubscribers($session)]);
        $registry->add(TriggerSubscriptionFixture::createSubscription('w0:0', 0, HaTrigger::onSunset()));
        $registry->add(TriggerSubscriptionFixture::createSubscription('w1:0', 1, HaTrigger::onSunset()));
        $registry->add(TriggerSubscriptionFixture::createSubscription('w1:1', 1, HaTrigger::onSunrise()));

        new TriggerRejections($registry, new WorkerSlotRegistry())->refuseSubscribers(TriggerSpec::fromSpec(HaTrigger::onSunset()), 'Invalid trigger');

        self::assertSame(['w1:1'], $registry->listSubscriptions()->mapToList(
            static fn(BrokerSubscription $subscription): string => $subscription->subscriptionId->value,
        ));
        self::assertCount(1, $session->unsubscribedTriggers);
    }
}
