<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker\Message;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Selector\Selector;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\Message\SubscribeHandler;
use Stewart\Runtime\Broker\OutboxLimits;
use Stewart\Runtime\Broker\SubscriptionRegistry;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Ipc\Message\Subscribe;
use Stewart\Runtime\Ipc\Message\SubscriptionAck;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Model\SubscriptionKind;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeWorkerProcess;
use Stewart\Testing\Time\EventLoopTicks;

#[CoversClass(SubscribeHandler::class)]
final class SubscribeHandlerTest extends TestCase
{
    private FakeWorkerProcess $process;

    private SubscriptionRegistry $registry;

    protected function setUp(): void
    {
        $this->process = new FakeWorkerProcess();
        $this->registry = new SubscriptionRegistry();
    }

    public function testMqttFilterSubscriptionIsAccepted(): void
    {
        $ack = $this->subscribe(SubscriptionKind::Mqtt, Selector::mqttFilter('home/#'));

        self::assertTrue($ack->accepted);
        self::assertCount(1, $this->registry->listSubscriptions());
    }

    public function testMismatchedSelectorIsRefused(): void
    {
        $ack = $this->subscribe(SubscriptionKind::Mqtt, Selector::glob('home/*'));

        self::assertFalse($ack->accepted);
        self::assertSame('mqtt subscriptions do not accept glob selectors.', $ack->reason);
        self::assertCount(0, $this->registry->listSubscriptions());
    }

    public function testWorkerLocalKindIsRefused(): void
    {
        $ack = $this->subscribe(SubscriptionKind::StateChange, Selector::exact('light.hall'));

        self::assertFalse($ack->accepted);
        self::assertCount(0, $this->registry->listSubscriptions());
    }

    public function testTriggerKindIsRefused(): void
    {
        $ack = $this->subscribe(SubscriptionKind::Trigger, Selector::exact('sunset-key'));

        self::assertFalse($ack->accepted);
        self::assertCount(0, $this->registry->listSubscriptions());
    }

    private function subscribe(SubscriptionKind $kind, Selector $selector): SubscriptionAck
    {
        $handle = new WorkerHandle(
            new WorkerId(0),
            $this->process,
            new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class)])),
            new NullLogger(),
            new OutboxLimits(10, 256),
        );

        new SubscribeHandler($this->registry, new NullLogger())->handle(
            $handle,
            new Subscribe(new SubscriptionId('w0:1'), ResourceScope::forApp(new AppId('demo')), $kind, $selector),
        );
        EventLoopTicks::settleUntil(fn(): bool => $this->process->channel->listSentOfType(SubscriptionAck::class) !== []);

        return $this->process->channel->listSentOfType(SubscriptionAck::class)[0];
    }
}
