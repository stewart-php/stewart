<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\App\AppCatalog;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\AppPause;
use Stewart\Runtime\App\AppPauseOutcome;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppPauseOverrideCodec;
use Stewart\Runtime\Broker\AppPauseOverrideStore;
use Stewart\Runtime\Broker\AppPauseRegistry;
use Stewart\Runtime\Broker\AppPauseService;
use Stewart\Runtime\Broker\DaemonStartTime;
use Stewart\Runtime\Broker\WorkerSlotRegistry;
use Stewart\Runtime\Exception\AppError;
use Stewart\Runtime\Lifecycle\AppPauseOverridePersistence;
use Stewart\Runtime\Lifecycle\AppPauseSource;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Store\StorePrefix;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Store\InMemoryStoreBackend;
use Stewart\Testing\Time\VirtualClock;

#[CoversClass(AppPauseService::class)]
#[CoversClass(AppPauseOutcome::class)]
final class AppPauseServiceTest extends TestCase
{
    use AssertsReason;

    private const string OVERRIDE_KEY = 'stewart:runtime:app-pause:demo';

    private VirtualClock $clock;

    private RecordingLogger $logger;

    private InMemoryStoreBackend $backend;

    protected function setUp(): void
    {
        $this->clock = new VirtualClock();
        $this->logger = new RecordingLogger();
        $this->backend = new InMemoryStoreBackend($this->clock);
    }

    public function testChangesAreLoggedOnce(): void
    {
        $registry = $this->createRegistry();
        $service = $this->createService($registry);

        $service->pauseApp(new AppId('demo'), AppPauseSource::Control);
        $service->pauseApp(new AppId('demo'), AppPauseSource::Control);
        $service->resumeApp(new AppId('demo'), AppPauseSource::Control);
        $service->resumeApp(new AppId('demo'), AppPauseSource::Control);

        self::assertSame(['App paused', 'App resumed'], $this->logger->listMessagesAt('info'));
        self::assertFalse($registry->isPaused(new AppId('demo')));
    }

    public function testRepeatedPauseKeepsFirstSince(): void
    {
        $registry = $this->createRegistry();
        $service = $this->createService($registry);
        $pausedAt = $this->clock->getNow();

        $service->pauseApp(new AppId('demo'), AppPauseSource::Control);
        $this->clock->skip(Duration::minutes(5));
        $service->pauseApp(new AppId('demo'), AppPauseSource::Control);

        self::assertEquals(new AppPause(new AppId('demo'), $pausedAt, AppPauseSource::Control), $registry->findPause(new AppId('demo')));
    }

    public function testEveryCommandWritesItsOverride(): void
    {
        $service = $this->createService($this->createRegistry());

        self::assertEquals(new AppPauseOutcome(true, AppPauseOverridePersistence::Stored), $service->pauseApp(new AppId('demo'), AppPauseSource::Control));
        $this->backend->remove(self::OVERRIDE_KEY);
        self::assertEquals(new AppPauseOutcome(false, AppPauseOverridePersistence::Stored), $service->pauseApp(new AppId('demo'), AppPauseSource::Control));
        self::assertStringContainsString('"paused":true', (string) $this->backend->read(self::OVERRIDE_KEY));

        $service->resumeApp(new AppId('demo'), AppPauseSource::Control);
        self::assertStringContainsString('"paused":false', (string) $this->backend->read(self::OVERRIDE_KEY));
    }

    public function testPauseOnConfigPausedAppPinsIt(): void
    {
        $service = $this->createService($this->createRegistry(startsPaused: true));

        self::assertFalse($service->pauseApp(new AppId('demo'), AppPauseSource::Control)->changed);
        self::assertStringContainsString('"paused":true', (string) $this->backend->read(self::OVERRIDE_KEY));
    }

    public function testFailedWriteStillAppliesChange(): void
    {
        $registry = $this->createRegistry();
        $this->backend->simulateOutage('down');

        self::assertEquals(new AppPauseOutcome(true, AppPauseOverridePersistence::Failed), $this->createService($registry)->pauseApp(new AppId('demo'), AppPauseSource::Control));
        self::assertTrue($registry->isPaused(new AppId('demo')));
    }

    public function testWithoutStoreOutcomeSaysNotConfigured(): void
    {
        $service = new AppPauseService(
            self::createCatalog(),
            $this->createRegistry(),
            new AppPauseOverrideStore(new AppPauseOverrideCodec(AppPauseOverrideCodec::createOverrideWireMapper()), new NullLogger()),
            new WorkerSlotRegistry(),
            $this->clock,
            $this->logger,
        );

        self::assertSame(AppPauseOverridePersistence::NotConfigured, $service->pauseApp(new AppId('demo'), AppPauseSource::Control)->persistence);
    }

    public function testUnknownAppIsRefused(): void
    {
        $service = $this->createService($this->createRegistry());

        $this->assertThrowsReason(AppError::Unknown, fn() => $service->pauseApp(new AppId('ghost'), AppPauseSource::Control));
        $this->assertThrowsReason(AppError::Unknown, fn() => $service->resumeApp(new AppId('ghost'), AppPauseSource::Control));
    }

    public function testDisabledAppIsRefusedAndStaysUnpaused(): void
    {
        $registry = $this->createRegistry();

        $this->assertThrowsReason(AppError::Disabled, fn() => $this->createService($registry)->pauseApp(new AppId('dormant'), AppPauseSource::Control));
        self::assertFalse($registry->isPaused(new AppId('dormant')));
        self::assertSame([], $this->backend->keysWithPrefix('stewart:'));
    }

    private function createRegistry(bool $startsPaused = false): AppPauseRegistry
    {
        return new AppPauseRegistry(
            AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class, startsPaused: $startsPaused)]),
            new DaemonStartTime($this->clock),
        );
    }

    private function createService(AppPauseRegistry $registry): AppPauseService
    {
        return new AppPauseService(
            self::createCatalog(),
            $registry,
            new AppPauseOverrideStore(new AppPauseOverrideCodec(AppPauseOverrideCodec::createOverrideWireMapper()), new NullLogger(), $this->backend, new StorePrefix('stewart')),
            new WorkerSlotRegistry(),
            $this->clock,
            $this->logger,
        );
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
