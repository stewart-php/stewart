<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppPauseRegistry;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;

#[CoversClass(AppPauseRegistry::class)]
final class AppPauseRegistryTest extends TestCase
{
    public function testPauseAndResumeReportOnlyChanges(): void
    {
        $registry = new AppPauseRegistry(AppDefinitionCollection::keyedByAppId([]));

        self::assertTrue($registry->pauseApp(new AppId('demo')));
        self::assertFalse($registry->pauseApp(new AppId('demo')));
        self::assertTrue($registry->isPaused(new AppId('demo')));
        self::assertTrue($registry->resumeApp(new AppId('demo')));
        self::assertFalse($registry->resumeApp(new AppId('demo')));
        self::assertFalse($registry->isPaused(new AppId('demo')));
    }

    public function testAppsConfiguredPausedStartPaused(): void
    {
        $registry = new AppPauseRegistry(AppDefinitionCollection::keyedByAppId([
            new AppDefinition(new AppId('demo'), Demo::class, startsPaused: true),
            new AppDefinition(new AppId('echo'), Demo::class),
        ]));

        self::assertSame(['demo'], $registry->listPausedAppIds()->toStrings());
    }

    public function testListsPausedAppsInPauseOrder(): void
    {
        $registry = new AppPauseRegistry(AppDefinitionCollection::keyedByAppId([]));
        $registry->pauseApp(new AppId('echo'));
        $registry->pauseApp(new AppId('demo'));
        $registry->pauseApp(new AppId('other'));
        $registry->resumeApp(new AppId('demo'));

        self::assertSame(['echo', 'other'], $registry->listPausedAppIds()->toStrings());
    }
}
