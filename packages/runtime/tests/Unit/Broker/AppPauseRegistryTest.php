<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\AppPause;
use Stewart\Runtime\App\AppPauseOverride;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppPauseRegistry;
use Stewart\Runtime\Broker\DaemonStartTime;
use Stewart\Runtime\Lifecycle\AppPauseSource;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Testing\Time\VirtualClock;

#[CoversClass(AppPauseRegistry::class)]
final class AppPauseRegistryTest extends TestCase
{
    public function testOverrideReportsOnlyEffectiveChanges(): void
    {
        $registry = self::createRegistry();

        self::assertTrue($registry->recordOverride(self::createOverride('demo', true)));
        self::assertFalse($registry->recordOverride(self::createOverride('demo', true)));
        self::assertTrue($registry->isPaused(new AppId('demo')));
        self::assertTrue($registry->recordOverride(self::createOverride('demo', false)));
        self::assertFalse($registry->recordOverride(self::createOverride('demo', false)));
        self::assertFalse($registry->isPaused(new AppId('demo')));
    }

    public function testConfigPauseHoldsFromDaemonStart(): void
    {
        $clock = new VirtualClock();
        $startTime = new DaemonStartTime($clock);
        $registry = new AppPauseRegistry(AppDefinitionCollection::keyedByAppId([
            new AppDefinition(new AppId('demo'), Demo::class, startsPaused: true),
            new AppDefinition(new AppId('echo'), Demo::class),
        ]), $startTime);
        $clock->skip(Duration::seconds(5));
        $startTime->recordStart();

        self::assertSame(['demo'], $registry->listPausedAppIds()->toStrings());
        self::assertEquals(new AppPause(new AppId('demo'), $startTime->getStartedAt(), AppPauseSource::Config), $registry->findPause(new AppId('demo')));
    }

    public function testResumeOverrideBeatsConfigPause(): void
    {
        $registry = self::createRegistry(startsPaused: true);

        self::assertTrue($registry->recordOverride(self::createOverride('demo', false)));
        self::assertFalse($registry->isPaused(new AppId('demo')));
        self::assertNull($registry->findPause(new AppId('demo')));
        self::assertSame([], $registry->listPausedAppIds()->toStrings());
    }

    public function testPauseOverrideOnConfigPauseIsNoChange(): void
    {
        $registry = self::createRegistry(startsPaused: true);
        $override = new AppPauseOverride(new AppId('demo'), true, Instant::fromEpochMicroseconds(7), AppPauseSource::Control);

        self::assertFalse($registry->recordOverride($override));
        self::assertEquals(new AppPause(new AppId('demo'), $override->since, AppPauseSource::Control), $registry->findPause(new AppId('demo')));
        self::assertSame(['demo'], $registry->listPausedAppIds()->toStrings());
    }

    public function testForgottenOverrideFallsBackToConfig(): void
    {
        $registry = self::createRegistry(startsPaused: true);
        $registry->recordOverride(self::createOverride('demo', false));

        self::assertTrue($registry->forgetOverride(new AppId('demo')));
        self::assertFalse($registry->forgetOverride(new AppId('demo')));
        self::assertTrue($registry->isPaused(new AppId('demo')));
        self::assertNull($registry->findOverride(new AppId('demo')));
    }

    public function testListsPausedAppsInPauseOrder(): void
    {
        $registry = self::createRegistry();
        $registry->recordOverride(self::createOverride('echo', true));
        $registry->recordOverride(self::createOverride('demo', true));
        $registry->recordOverride(self::createOverride('other', true));
        $registry->recordOverride(self::createOverride('demo', false));
        $registry->recordOverride(self::createOverride('echo', false));
        $registry->recordOverride(self::createOverride('echo', true));

        self::assertSame(['other', 'echo'], $registry->listPausedAppIds()->toStrings());
        self::assertNull($registry->findPause(new AppId('demo')));
    }

    private static function createRegistry(bool $startsPaused = false): AppPauseRegistry
    {
        return new AppPauseRegistry(
            AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class, startsPaused: $startsPaused)]),
            new DaemonStartTime(new VirtualClock()),
        );
    }

    private static function createOverride(string $appId, bool $paused): AppPauseOverride
    {
        return new AppPauseOverride(new AppId($appId), $paused, Instant::fromEpochMicroseconds(0), AppPauseSource::Control);
    }
}
