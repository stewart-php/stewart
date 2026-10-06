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
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppPauseRegistry;
use Stewart\Runtime\Broker\DaemonStartTime;
use Stewart\Runtime\Lifecycle\AppPauseSource;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Testing\Time\VirtualClock;

#[CoversClass(AppPauseRegistry::class)]
final class AppPauseRegistryTest extends TestCase
{
    public function testPauseAndResumeReportOnlyChanges(): void
    {
        $registry = new AppPauseRegistry(AppDefinitionCollection::keyedByAppId([]), new DaemonStartTime(new VirtualClock()));

        self::assertTrue($registry->pauseApp(self::createPause('demo')));
        self::assertFalse($registry->pauseApp(self::createPause('demo')));
        self::assertTrue($registry->isPaused(new AppId('demo')));
        self::assertTrue($registry->resumeApp(new AppId('demo')));
        self::assertFalse($registry->resumeApp(new AppId('demo')));
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

    public function testResumedConfigPauseCanBePausedAgain(): void
    {
        $registry = new AppPauseRegistry(
            AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class, startsPaused: true)]),
            new DaemonStartTime(new VirtualClock()),
        );

        self::assertTrue($registry->resumeApp(new AppId('demo')));
        self::assertSame([], $registry->listPausedAppIds()->toStrings());
        self::assertTrue($registry->pauseApp(self::createPause('demo')));
        self::assertSame(AppPauseSource::Control, $registry->findPause(new AppId('demo'))?->source);
    }

    public function testRepeatedPauseKeepsFirstPause(): void
    {
        $registry = new AppPauseRegistry(AppDefinitionCollection::keyedByAppId([]), new DaemonStartTime(new VirtualClock()));
        $first = new AppPause(new AppId('demo'), Instant::fromEpochMicroseconds(1), AppPauseSource::Control);
        $registry->pauseApp($first);
        $registry->pauseApp(new AppPause(new AppId('demo'), Instant::fromEpochMicroseconds(2), AppPauseSource::Config));

        self::assertSame($first, $registry->findPause(new AppId('demo')));
    }

    public function testListsPausedAppsInPauseOrder(): void
    {
        $registry = new AppPauseRegistry(AppDefinitionCollection::keyedByAppId([]), new DaemonStartTime(new VirtualClock()));
        $registry->pauseApp(self::createPause('echo'));
        $registry->pauseApp(self::createPause('demo'));
        $registry->pauseApp(self::createPause('other'));
        $registry->resumeApp(new AppId('demo'));

        self::assertSame(['echo', 'other'], $registry->listPausedAppIds()->toStrings());
        self::assertNull($registry->findPause(new AppId('demo')));
    }

    private static function createPause(string $appId): AppPause
    {
        return new AppPause(new AppId($appId), Instant::fromEpochMicroseconds(0), AppPauseSource::Control);
    }
}
