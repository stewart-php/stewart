<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Runtime\App\AppCatalog;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\AppPause;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppPauseRegistry;
use Stewart\Runtime\Broker\AppPauseService;
use Stewart\Runtime\Broker\DaemonStartTime;
use Stewart\Runtime\Broker\WorkerSlotRegistry;
use Stewart\Runtime\Exception\AppError;
use Stewart\Runtime\Lifecycle\AppPauseSource;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\VirtualClock;

#[CoversClass(AppPauseService::class)]
final class AppPauseServiceTest extends TestCase
{
    use AssertsReason;

    public function testChangesAreLoggedOnce(): void
    {
        $logger = new RecordingLogger();
        $registry = new AppPauseRegistry(AppDefinitionCollection::keyedByAppId([]), new DaemonStartTime(new VirtualClock()));
        $service = new AppPauseService(self::createCatalog(), $registry, new WorkerSlotRegistry(), new VirtualClock(), $logger);

        $service->pauseApp(new AppId('demo'), AppPauseSource::Control);
        $service->pauseApp(new AppId('demo'), AppPauseSource::Control);
        $service->resumeApp(new AppId('demo'), AppPauseSource::Control);
        $service->resumeApp(new AppId('demo'), AppPauseSource::Control);

        self::assertSame(['App paused', 'App resumed'], $logger->listMessagesAt('info'));
        self::assertFalse($registry->isPaused(new AppId('demo')));
    }

    public function testPauseRecordsSourceAndTime(): void
    {
        $clock = new VirtualClock();
        $registry = new AppPauseRegistry(AppDefinitionCollection::keyedByAppId([]), new DaemonStartTime($clock));
        $service = new AppPauseService(self::createCatalog(), $registry, new WorkerSlotRegistry(), $clock, new RecordingLogger());

        $service->pauseApp(new AppId('demo'), AppPauseSource::Control);

        self::assertEquals(new AppPause(new AppId('demo'), $clock->getNow(), AppPauseSource::Control), $registry->findPause(new AppId('demo')));
    }

    public function testUnknownAppIsRefused(): void
    {
        $service = new AppPauseService(self::createCatalog(), new AppPauseRegistry(AppDefinitionCollection::keyedByAppId([]), new DaemonStartTime(new VirtualClock())), new WorkerSlotRegistry(), new VirtualClock(), new RecordingLogger());

        $this->assertThrowsReason(AppError::Unknown, fn() => $service->pauseApp(new AppId('ghost'), AppPauseSource::Control));
        $this->assertThrowsReason(AppError::Unknown, fn() => $service->resumeApp(new AppId('ghost'), AppPauseSource::Control));
    }

    public function testDisabledAppIsRefusedAndStaysUnpaused(): void
    {
        $registry = new AppPauseRegistry(AppDefinitionCollection::keyedByAppId([]), new DaemonStartTime(new VirtualClock()));
        $service = new AppPauseService(self::createCatalog(), $registry, new WorkerSlotRegistry(), new VirtualClock(), new RecordingLogger());

        $this->assertThrowsReason(AppError::Disabled, fn() => $service->pauseApp(new AppId('dormant'), AppPauseSource::Control));
        self::assertFalse($registry->isPaused(new AppId('dormant')));
    }

    private static function createCatalog(): AppCatalog
    {
        return new AppCatalog(
            AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class)]),
            AppIdCollection::fromIds([new AppId('demo'), new AppId('dormant')]),
            AppIdCollection::fromIds([]),
        );
    }
}
