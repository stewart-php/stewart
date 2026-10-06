<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppPauseRegistry;
use Stewart\Runtime\Broker\AppPauseService;
use Stewart\Runtime\Broker\WorkerSlotRegistry;
use Stewart\Testing\Logging\RecordingLogger;

#[CoversClass(AppPauseService::class)]
final class AppPauseServiceTest extends TestCase
{
    public function testChangesAreLoggedOnce(): void
    {
        $logger = new RecordingLogger();
        $registry = new AppPauseRegistry(AppDefinitionCollection::keyedByAppId([]));
        $service = new AppPauseService($registry, new WorkerSlotRegistry(), $logger);

        $service->pauseApp(new AppId('demo'));
        $service->pauseApp(new AppId('demo'));
        $service->resumeApp(new AppId('demo'));
        $service->resumeApp(new AppId('demo'));

        self::assertSame(['App paused', 'App resumed'], $logger->listMessagesAt('info'));
        self::assertFalse($registry->isPaused(new AppId('demo')));
    }
}
