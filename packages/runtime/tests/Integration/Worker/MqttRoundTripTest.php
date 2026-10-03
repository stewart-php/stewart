<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Integration\Worker;

use Amp\Future;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Mqtt\MqttQos;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\EventRouter;
use Stewart\Runtime\Broker\Mqtt\MqttFilterSubscriptions;
use Stewart\Runtime\Broker\OutboxLimits;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Broker\BrokerKernelFixture;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Runtime\Tests\Fixtures\Broker\Mqtt\RecordingMqttLink;
use Stewart\Runtime\Tests\Fixtures\Broker\WorkerPoolFixture;
use Stewart\Runtime\Tests\Fixtures\Protocol\MqttRelay;
use Stewart\Runtime\Tests\Fixtures\Worker\InMemoryWorkerSpawner;
use Stewart\Runtime\Worker\WorkerMqtt;
use Stewart\Testing\Logging\RecordingLogger;

use function Amp\async;

#[CoversClass(EventRouter::class)]
#[CoversClass(MqttFilterSubscriptions::class)]
#[CoversClass(WorkerMqtt::class)]
final class MqttRoundTripTest extends TestCase
{
    private const float WAIT_SECONDS = 5;

    public function testAppReceivesAndPublishesThroughLink(): void
    {
        $link = new RecordingMqttLink();
        $logger = new RecordingLogger();
        $pools = WorkerPoolFixture::createWorkerPool(new InMemoryWorkerSpawner(IpcCodec::createForWorkerBootstrap()), logger: $logger, outboxLimits: new OutboxLimits(100, 256));
        $broker = BrokerKernelFixture::boot(
            new FakeHaSession(),
            $pools,
            WorkerSlotCollection::fromWorkerSlots([new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('mqtt-relay'), MqttRelay::class)]))]),
            AppIdCollection::fromIds([new AppId('mqtt-relay')]),
            $logger,
            ['shutdown_grace' => '1s', 'mqtt' => ['url' => 'mqtt://broker.test']],
            mqttLink: $link,
        );
        $subscribed = $link->waitForNextSubscribe();

        /** @var Future<null> $running */
        $running = async($broker->lifecycle->run(...));

        self::assertSame(MqttRelay::FILTER, $subscribed->await(new TimeoutCancellation(self::WAIT_SECONDS)));

        $published = $link->waitForNextPublish();
        $link->receiveFromServer(new MqttMessage('home/hall/temp', '21.5'));
        $relayed = $published->await(new TimeoutCancellation(self::WAIT_SECONDS));

        self::assertSame(MqttRelay::RELAY_TOPIC, $relayed->topic);
        self::assertSame('{"from":"home/hall/temp","payload":"21.5"}', $relayed->payload);
        self::assertSame(MqttQos::AtLeastOnce, $relayed->qos);
        self::assertTrue($relayed->retain);

        $broker->run->stop('test done');
        $running->await(new TimeoutCancellation(self::WAIT_SECONDS));

        self::assertTrue($link->closed);
    }
}
