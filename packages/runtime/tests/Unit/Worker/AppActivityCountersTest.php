<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Runtime\Ipc\Message\Publish;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Tests\Fixtures\Ipc\NullTransport;
use Stewart\Runtime\Tests\Fixtures\Worker\WorkerHaContextFixture;
use Stewart\Runtime\Worker\AppActivity;
use Stewart\Runtime\Worker\AppActivityCounters;
use Stewart\Testing\Time\ManualTimers;

#[CoversClass(AppActivityCounters::class)]
#[CoversClass(AppActivity::class)]
final class AppActivityCountersTest extends TestCase
{
    public function testAppNobodyHasSeenIsIdle(): void
    {
        self::assertTrue(new AppActivityCounters()->findOrCreateActivityForScope(self::createScope('ghost'))->isIdle());
    }

    public function testEveryKindOfActivityIsCountedPerApp(): void
    {
        $counters = new AppActivityCounters();
        $demo = $counters->findOrCreateActivityForScope(self::createScope('demo'));
        $demo->recordDeliveredEvent();
        $demo->recordDeliveredEvent();
        $demo->recordDroppedEvent();
        $demo->recordScheduleRun();
        $demo->recordPublish();
        $counters->findOrCreateActivityForScope(self::createScope('echo'))->recordPublish();

        self::assertSame([2, 1, 1, 1], [$demo->delivered, $demo->dropped, $demo->scheduleRuns, $demo->publishes]);
        self::assertSame(1, $counters->findOrCreateActivityForScope(self::createScope('echo'))->publishes);
        self::assertFalse($demo->isIdle());
    }

    public function testPublishesAreCountedPerScope(): void
    {
        $counters = new AppActivityCounters();
        $transport = new NullTransport();
        $timers = new ManualTimers();
        $context = WorkerHaContextFixture::createWorkerHaContext(
            transport: $transport,
            scope: ResourceScope::shared(),
            timers: $timers,
            activityCounters: $counters,
        )->forApp(new AppId('demo'));

        $context->publish('demo.hello', ['x' => 1]);

        self::assertSame(1, $counters->findOrCreateActivityForScope(self::createScope('demo'))->publishes);
        self::assertSame(0, $counters->findOrCreateActivityForScope(ResourceScope::shared())->publishes);
        self::assertCount(1, array_filter($transport->sent, static fn(object $m): bool => $m instanceof Publish));
    }

    private static function createScope(string $appId): ResourceScope
    {
        return ResourceScope::forApp(new AppId($appId));
    }
}
