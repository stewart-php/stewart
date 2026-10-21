<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Broker\BrokerSubscription;
use Stewart\Runtime\Broker\EventRouter;
use Stewart\Runtime\Broker\SubscriptionRegistry;
use Stewart\Runtime\Broker\SubscriptionRoutes;
use Stewart\Runtime\Broker\WorkerSlotRegistry;
use Stewart\Runtime\Ipc\Message\Publish;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Model\SubscriptionKind;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Testing\Logging\RecordingLogger;

#[CoversClass(EventRouter::class)]
#[CoversClass(SubscriptionRoutes::class)]
#[CoversClass(SubscriptionRegistry::class)]
final class EventRouterTest extends TestCase
{
    private SubscriptionRegistry $registry;

    private RecordingLogger $logger;

    private EventRouter $router;

    protected function setUp(): void
    {
        $this->registry = new SubscriptionRegistry();
        $this->logger = new RecordingLogger();
        $this->router = new EventRouter(new WorkerSlotRegistry(), $this->registry, $this->logger);
    }

    public function testRoutedEventNamesEachMatchedAppOnce(): void
    {
        $this->subscribe('w1:1', 1, 'porch', SubscriptionKind::Event, Selector::exact('doorbell'));
        $this->subscribe('w0:1', 0, 'hall', SubscriptionKind::Event, Selector::any());
        $this->subscribe('w1:2', 1, 'porch', SubscriptionKind::Event, Selector::any());
        $this->subscribe('w0:2', 0, 'kitchen', SubscriptionKind::Event, Selector::exact('timer_finished'));

        $this->router->routeEvent(new HaEvent('doorbell'));

        self::assertSame(['Home Assistant event routed'], $this->logger->listMessagesAt('debug'));
        self::assertSame(['hall', 'porch'], $this->logger->records->getLast()?->context['apps'] ?? null);
    }

    public function testTopicAndMqttLinesNameNoAppWhenNoneListens(): void
    {
        $this->router->routePublish(new Publish('house.mode', 'away', ResourceScope::forApp(new AppId('presence')), Instant::fromEpochMicroseconds(0)));
        $this->router->routeMqttMessage(new MqttMessage('zigbee/remote', '{}'));

        self::assertSame(['Topic published', 'MQTT message routed'], $this->logger->listMessagesAt('debug'));
        self::assertSame([], $this->logger->records->getLast()?->context['apps'] ?? null);
    }

    private function subscribe(string $subscriptionId, int $workerId, string $appId, SubscriptionKind $kind, Selector $selector): void
    {
        $this->registry->add(new BrokerSubscription(SubscriptionId::fromString($subscriptionId), new WorkerId($workerId), ResourceScope::forApp(new AppId($appId)), $kind, $selector));
    }
}
