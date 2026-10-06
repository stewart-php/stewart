<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Stewart\Contracts\App\AppId;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\HaCallSlots;
use Stewart\Runtime\Broker\OutboxLimits;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Config\ServiceCallPolicy;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeWorkerProcess;
use Stewart\Testing\Logging\RecordingLogger;

#[CoversClass(HaCallSlots::class)]
final class HaCallSlotsTest extends TestCase
{
    public function testWorkerAtLimitIsRefused(): void
    {
        $slots = new HaCallSlots(new ServiceCallPolicy(perWorker: 1, total: 0), new NullLogger());
        $worker = self::createHandle(0);

        self::assertNull($slots->findRefusalReason($worker));
        $slots->acquire($worker);

        self::assertSame('this worker already has 1 calls to Home Assistant in flight', $slots->findRefusalReason($worker));
    }

    public function testOtherWorkersServedWhileOneIsAtLimit(): void
    {
        $slots = new HaCallSlots(new ServiceCallPolicy(perWorker: 1, total: 0), new NullLogger());
        $busy = self::createHandle(0);
        $slots->acquire($busy);

        self::assertNull($slots->findRefusalReason(self::createHandle(1)), 'One noisy worker must not starve the others.');
    }

    public function testGlobalLimitBoundsEveryWorkerTogether(): void
    {
        $slots = new HaCallSlots(new ServiceCallPolicy(perWorker: 0, total: 2), new NullLogger());
        $slots->acquire(self::createHandle(0));
        $second = self::createHandle(1);
        $slots->acquire($second);

        self::assertSame(2, $slots->inFlight);
        self::assertSame('the broker already has 2 calls to Home Assistant in flight', $slots->findRefusalReason($second));

        $slots->release($second);

        self::assertSame(1, $slots->inFlight);
        self::assertNull($slots->findRefusalReason($second));
    }

    public function testReplacementStartsWithoutPredecessorCalls(): void
    {
        $slots = new HaCallSlots(new ServiceCallPolicy(perWorker: 1, total: 0), new NullLogger());
        $dead = self::createHandle(0);
        $replacement = self::createHandle(0);
        $slots->acquire($dead);

        self::assertNull($slots->findRefusalReason($replacement));
        self::assertSame(0, $slots->countInFlightCallsFor($replacement));
    }

    public function testRefusalsAreCountedAndWarnedOnce(): void
    {
        $logger = new RecordingLogger();
        $slots = new HaCallSlots(new ServiceCallPolicy(perWorker: 1, total: 0), $logger);
        $worker = self::createHandle(0);

        $slots->recordRefusal($worker, ResourceScope::forApp(new AppId('demo')));
        $slots->recordRefusal($worker, ResourceScope::forApp(new AppId('demo')));

        self::assertSame(2, $slots->refusedCalls);
        self::assertSame(['Refusing calls; Home Assistant is not keeping up'], $logger->listMessagesAt(LogLevel::WARNING));
    }

    private static function createHandle(int $workerId): WorkerHandle
    {
        return new WorkerHandle(
            id: new WorkerId($workerId),
            process: new FakeWorkerProcess(),
            slot: new WorkerSlot(new WorkerId($workerId), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class)])),
            logger: new NullLogger(),
            outboxLimits: new OutboxLimits(100, 256),
        );
    }
}
