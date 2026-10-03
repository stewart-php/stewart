<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\MqttError;
use Stewart\Contracts\Exception\SelectorError;
use Stewart\Contracts\Mqtt\MqttQos;
use Stewart\Runtime\Dispatch\LocalDispatcher;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Message\MqttPublish;
use Stewart\Runtime\Ipc\Message\Subscribe;
use Stewart\Runtime\Ipc\Transport;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\SubscriptionKind;
use Stewart\Runtime\Scope\ScopeLifecycle;
use Stewart\Runtime\Tests\Fixtures\Ipc\FailingTransport;
use Stewart\Runtime\Tests\Fixtures\Ipc\FakeWorkerTransport;
use Stewart\Runtime\Tests\Fixtures\Worker\RecordingDispatchListener;
use Stewart\Runtime\Worker\AppActivityCounters;
use Stewart\Runtime\Worker\BrokerSubscriptions;
use Stewart\Runtime\Worker\Context\DispatchStreams;
use Stewart\Runtime\Worker\WorkerMqtt;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\ManualTimers;

#[CoversClass(WorkerMqtt::class)]
final class WorkerMqttTest extends TestCase
{
    use AssertsReason;

    public function testPublishSendsMessageInAppScope(): void
    {
        $transport = new FakeWorkerTransport();

        self::createMqtt($transport)->forApp(new AppId('demo'))->publish('home/light', ['on' => true], MqttQos::AtLeastOnce, true);

        $sent = $transport->listSentOfType(MqttPublish::class);
        self::assertCount(1, $sent);
        self::assertSame('home/light', $sent[0]->message->topic);
        self::assertSame('{"on":true}', $sent[0]->message->payload);
        self::assertSame(MqttQos::AtLeastOnce, $sent[0]->message->qos);
        self::assertTrue($sent[0]->message->retain);
        self::assertSame('demo', $sent[0]->publisherScope->wireValue());
    }

    public function testBrokenChannelFailsThePublish(): void
    {
        $mqtt = self::createMqtt(new FailingTransport(TransportException::sendFailed(new RuntimeException('broken pipe'))));

        self::assertThrowsReason(MqttError::PublishFailed, static fn() => $mqtt->publish('home/light', 'on'));
    }

    public function testDisabledMqttRefusesAnApp(): void
    {
        $mqtt = self::createMqtt(new FakeWorkerTransport(), brokerMqttEnabled: false);

        self::assertThrowsReason(MqttError::NotConfigured, static fn() => $mqtt->forApp(new AppId('demo')));
        self::assertThrowsReason(MqttError::NotConfigured, static fn() => $mqtt->publish('home/light', 'on'));
    }

    public function testWatchRegistersMqttFilterSubscription(): void
    {
        $transport = new FakeWorkerTransport();

        self::createMqtt($transport)->forApp(new AppId('demo'))->watchMessages('home/+/temp')->subscribe(static function (): void {});

        $subscribes = $transport->listSentOfType(Subscribe::class);
        self::assertCount(1, $subscribes);
        self::assertSame(SubscriptionKind::Mqtt, $subscribes[0]->kind);
        self::assertSame('mqtt_filter:home/+/temp', $subscribes[0]->selector->toCanonicalKey());
        self::assertSame('demo', $subscribes[0]->scope->wireValue());
    }

    public function testMalformedFilterIsRejected(): void
    {
        $mqtt = self::createMqtt(new FakeWorkerTransport());

        self::assertThrowsReason(SelectorError::MqttFilterInvalid, static fn() => $mqtt->watchMessages('home/#/temp'));
    }

    private static function createMqtt(Transport $transport, bool $brokerMqttEnabled = true): WorkerMqtt
    {
        $listener = new RecordingDispatchListener();
        $dispatcher = new LocalDispatcher('w0', 10, $listener, new BrokerSubscriptions($transport, new RecordingLogger()), new ScopeLifecycle());

        return new WorkerMqtt(
            $transport,
            new DispatchStreams($dispatcher, new ManualTimers()),
            new AppActivityCounters(),
            $brokerMqttEnabled,
            ResourceScope::shared(),
        );
    }
}
