<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker\Trigger;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\Trigger\HaTrigger;
use Stewart\Contracts\Trigger\TriggerSpec;
use Stewart\Runtime\Broker\BrokerSubscription;
use Stewart\Runtime\Broker\SubscriptionRegistry;
use Stewart\Runtime\Broker\Trigger\TriggerSubscribers;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Model\SubscriptionKind;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Runtime\Tests\Fixtures\Broker\TriggerSubscriptionFixture;

#[CoversClass(TriggerSubscribers::class)]
final class TriggerSubscribersTest extends TestCase
{
    private FakeHaSession $session;

    private SubscriptionRegistry $registry;

    protected function setUp(): void
    {
        $this->session = new FakeHaSession();
        $this->registry = new SubscriptionRegistry([new TriggerSubscribers($this->session)]);
    }

    public function testSharedSpecIsSubscribedOnce(): void
    {
        $this->registry->add(TriggerSubscriptionFixture::createSubscription('w0:0', 0, HaTrigger::onSunset()));
        $this->registry->add(TriggerSubscriptionFixture::createSubscription('w1:0', 1, HaTrigger::onSunset()));
        $this->registry->add(TriggerSubscriptionFixture::createSubscription('w1:1', 1, HaTrigger::onSunrise()));

        self::assertCount(2, $this->session->subscribedTriggers);
        self::assertTrue($this->session->subscribedTriggers[0]->hasSameTriggersAs(TriggerSpec::fromSpec(HaTrigger::onSunset())));
        self::assertTrue($this->session->subscribedTriggers[1]->hasSameTriggersAs(TriggerSpec::fromSpec(HaTrigger::onSunrise())));
    }

    public function testSpecIsDroppedWithItsLastSubscriber(): void
    {
        $this->registry->add(TriggerSubscriptionFixture::createSubscription('w0:0', 0, HaTrigger::onSunset()));
        $this->registry->add(TriggerSubscriptionFixture::createSubscription('w1:0', 1, HaTrigger::onSunset()));

        $this->registry->remove(new SubscriptionId('w0:0'));
        self::assertSame([], $this->session->unsubscribedTriggers);

        $this->registry->remove(new SubscriptionId('w1:0'));
        self::assertCount(1, $this->session->unsubscribedTriggers);
    }

    public function testWorkerExitDropsItsSpecs(): void
    {
        $this->registry->add(TriggerSubscriptionFixture::createSubscription('w0:0', 0, HaTrigger::onSunset()));
        $this->registry->add(TriggerSubscriptionFixture::createSubscription('w0:1', 0, HaTrigger::onSunrise()));

        $this->registry->removeWorkerSubscriptions(new WorkerId(0));

        self::assertCount(2, $this->session->unsubscribedTriggers);
    }

    public function testSubscriptionWithoutSpecIsIgnored(): void
    {
        $this->registry->add(new BrokerSubscription(
            new SubscriptionId('w0:0'),
            new WorkerId(0),
            ResourceScope::forApp(new AppId('demo')),
            SubscriptionKind::Event,
            Selector::exact('zha_event'),
        ));
        $this->registry->remove(new SubscriptionId('w0:0'));

        self::assertSame([], $this->session->subscribedTriggers);
        self::assertSame([], $this->session->unsubscribedTriggers);
    }
}
