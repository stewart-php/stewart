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
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHomeAssistant;
use Stewart\Runtime\Tests\Fixtures\Broker\TriggerSubscriptionFixture;
use Stewart\Testing\Time\EventLoopTicks;

#[CoversClass(TriggerSubscribers::class)]
final class TriggerSubscribersTest extends TestCase
{
    private FakeHomeAssistant $homeAssistant;

    private SubscriptionRegistry $registry;

    protected function setUp(): void
    {
        $this->homeAssistant = FakeHomeAssistant::createConnected();
        $this->registry = new SubscriptionRegistry([new TriggerSubscribers($this->homeAssistant->triggers)]);
    }

    public function testSharedSpecIsSubscribedOnce(): void
    {
        $this->registry->add(TriggerSubscriptionFixture::createSubscription('w0:0', 0, HaTrigger::onSunset()));
        $this->registry->add(TriggerSubscriptionFixture::createSubscription('w1:0', 1, HaTrigger::onSunset()));
        $this->registry->add(TriggerSubscriptionFixture::createSubscription('w1:1', 1, HaTrigger::onSunrise()));

        self::assertCount(2, $this->listSubscribedTriggers());
        self::assertTrue($this->listSubscribedTriggers()[0]->hasSameTriggersAs(TriggerSpec::fromSpec(HaTrigger::onSunset())));
        self::assertTrue($this->listSubscribedTriggers()[1]->hasSameTriggersAs(TriggerSpec::fromSpec(HaTrigger::onSunrise())));
    }

    public function testSpecIsDroppedWithItsLastSubscriber(): void
    {
        $this->registry->add(TriggerSubscriptionFixture::createSubscription('w0:0', 0, HaTrigger::onSunset()));
        $this->registry->add(TriggerSubscriptionFixture::createSubscription('w1:0', 1, HaTrigger::onSunset()));

        $this->registry->remove(new SubscriptionId('w0:0'));
        self::assertSame([], $this->listUnsubscribedTriggers());

        $this->registry->remove(new SubscriptionId('w1:0'));
        self::assertCount(1, $this->listUnsubscribedTriggers());
    }

    public function testWorkerExitDropsItsSpecs(): void
    {
        $this->registry->add(TriggerSubscriptionFixture::createSubscription('w0:0', 0, HaTrigger::onSunset()));
        $this->registry->add(TriggerSubscriptionFixture::createSubscription('w0:1', 0, HaTrigger::onSunrise()));

        $this->registry->removeWorkerSubscriptions(new WorkerId(0));

        self::assertCount(2, $this->listUnsubscribedTriggers());
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

        self::assertSame([], $this->listSubscribedTriggers());
        self::assertSame([], $this->listUnsubscribedTriggers());
    }

    /** @return list<TriggerSpec> */
    private function listSubscribedTriggers(): array
    {
        EventLoopTicks::settle();

        return $this->homeAssistant->listSubscribedTriggers();
    }

    /** @return list<TriggerSpec> */
    private function listUnsubscribedTriggers(): array
    {
        EventLoopTicks::settle();

        return $this->homeAssistant->listUnsubscribedTriggers();
    }
}
