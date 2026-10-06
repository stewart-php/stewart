<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Runtime\Broker\AppPauseRegistry;

#[CoversClass(AppPauseRegistry::class)]
final class AppPauseRegistryTest extends TestCase
{
    public function testPauseAndResumeReportOnlyChanges(): void
    {
        $registry = new AppPauseRegistry();

        self::assertTrue($registry->pauseApp(new AppId('demo')));
        self::assertFalse($registry->pauseApp(new AppId('demo')));
        self::assertTrue($registry->isPaused(new AppId('demo')));
        self::assertTrue($registry->resumeApp(new AppId('demo')));
        self::assertFalse($registry->resumeApp(new AppId('demo')));
        self::assertFalse($registry->isPaused(new AppId('demo')));
    }

    public function testListsPausedAppsInPauseOrder(): void
    {
        $registry = new AppPauseRegistry();
        $registry->pauseApp(new AppId('echo'));
        $registry->pauseApp(new AppId('demo'));
        $registry->pauseApp(new AppId('other'));
        $registry->resumeApp(new AppId('demo'));

        self::assertSame(['echo', 'other'], $registry->listPausedAppIds()->toStrings());
    }
}
