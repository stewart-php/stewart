<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Runtime\App\AppCatalog;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppPauseRegistry;
use Stewart\Runtime\Broker\AppPauseService;
use Stewart\Runtime\Broker\WorkerSlotRegistry;
use Stewart\Runtime\Exception\AppError;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Logging\RecordingLogger;

#[CoversClass(AppPauseService::class)]
final class AppPauseServiceTest extends TestCase
{
    use AssertsReason;

    public function testChangesAreLoggedOnce(): void
    {
        $logger = new RecordingLogger();
        $registry = new AppPauseRegistry(AppDefinitionCollection::keyedByAppId([]));
        $service = new AppPauseService(self::createCatalog(), $registry, new WorkerSlotRegistry(), $logger);

        $service->pauseApp(new AppId('demo'));
        $service->pauseApp(new AppId('demo'));
        $service->resumeApp(new AppId('demo'));
        $service->resumeApp(new AppId('demo'));

        self::assertSame(['App paused', 'App resumed'], $logger->listMessagesAt('info'));
        self::assertFalse($registry->isPaused(new AppId('demo')));
    }

    public function testUnknownAppIsRefused(): void
    {
        $service = new AppPauseService(self::createCatalog(), new AppPauseRegistry(AppDefinitionCollection::keyedByAppId([])), new WorkerSlotRegistry(), new RecordingLogger());

        $this->assertThrowsReason(AppError::Unknown, fn() => $service->pauseApp(new AppId('ghost')));
        $this->assertThrowsReason(AppError::Unknown, fn() => $service->resumeApp(new AppId('ghost')));
    }

    public function testDisabledAppIsRefusedAndStaysUnpaused(): void
    {
        $registry = new AppPauseRegistry(AppDefinitionCollection::keyedByAppId([]));
        $service = new AppPauseService(self::createCatalog(), $registry, new WorkerSlotRegistry(), new RecordingLogger());

        $this->assertThrowsReason(AppError::Disabled, fn() => $service->pauseApp(new AppId('dormant')));
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
