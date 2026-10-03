<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker\Mqtt;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Selector\Selector;
use Stewart\Runtime\Broker\BrokerSubscription;
use Stewart\Runtime\Broker\Mqtt\MqttFilterSubscriptions;
use Stewart\Runtime\Broker\SubscriptionRegistry;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Model\SubscriptionKind;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Broker\Mqtt\RecordingMqttLink;

#[CoversClass(MqttFilterSubscriptions::class)]
#[CoversClass(SubscriptionRegistry::class)]
final class MqttFilterSubscriptionsTest extends TestCase
{
    private RecordingMqttLink $link;

    private SubscriptionRegistry $registry;

    protected function setUp(): void
    {
        $this->link = new RecordingMqttLink();
        $this->registry = new SubscriptionRegistry([new MqttFilterSubscriptions($this->link)]);
    }

    public function testSharedFilterIsSubscribedOnce(): void
    {
        $this->registry->add(self::createSubscription('w0:0', 0, 'home/#'));
        $this->registry->add(self::createSubscription('w1:0', 1, 'home/#'));

        self::assertSame(['home/#'], $this->link->subscribedFilters);
    }

    public function testFilterIsDroppedWithItsLastSubscriber(): void
    {
        $this->registry->add(self::createSubscription('w0:0', 0, 'home/#'));
        $this->registry->add(self::createSubscription('w1:0', 1, 'home/#'));

        $this->registry->remove(new SubscriptionId('w0:0'));
        self::assertSame([], $this->link->unsubscribedFilters);

        $this->registry->remove(new SubscriptionId('w1:0'));
        self::assertSame(['home/#'], $this->link->unsubscribedFilters);
    }

    public function testWorkerExitDropsItsFilters(): void
    {
        $this->registry->add(self::createSubscription('w0:0', 0, 'home/+/temp'));
        $this->registry->add(self::createSubscription('w0:1', 0, 'garden/#'));

        $this->registry->removeWorkerSubscriptions(new WorkerId(0));

        self::assertEqualsCanonicalizing(['home/+/temp', 'garden/#'], $this->link->unsubscribedFilters);
    }

    public function testOtherKindsNeverReachTheServer(): void
    {
        $this->registry->add(new BrokerSubscription(
            new SubscriptionId('w0:0'),
            new WorkerId(0),
            ResourceScope::forApp(new AppId('demo')),
            SubscriptionKind::Topic,
            Selector::glob('home.*'),
        ));
        $this->registry->remove(new SubscriptionId('w0:0'));

        self::assertSame([], $this->link->subscribedFilters);
        self::assertSame([], $this->link->unsubscribedFilters);
    }

    private static function createSubscription(string $id, int $worker, string $filter): BrokerSubscription
    {
        return new BrokerSubscription(
            new SubscriptionId($id),
            new WorkerId($worker),
            ResourceScope::forApp(new AppId('demo')),
            SubscriptionKind::Mqtt,
            Selector::mqttFilter($filter),
        );
    }
}
