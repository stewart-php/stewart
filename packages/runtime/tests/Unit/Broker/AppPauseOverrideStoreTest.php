<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\StoreError;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\App\AppPauseOverride;
use Stewart\Runtime\Broker\AppPauseOverrideCodec;
use Stewart\Runtime\Broker\AppPauseOverrideStore;
use Stewart\Runtime\Lifecycle\AppPauseOverridePersistence;
use Stewart\Runtime\Lifecycle\AppPauseSource;
use Stewart\Store\StorePrefix;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Store\InMemoryStoreBackend;
use Stewart\Testing\Time\VirtualClock;

#[CoversClass(AppPauseOverrideStore::class)]
#[CoversClass(AppPauseOverrideCodec::class)]
final class AppPauseOverrideStoreTest extends TestCase
{
    use AssertsReason;

    private const string SINCE = '2026-10-06T08:00:00.000000Z';

    private InMemoryStoreBackend $backend;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->backend = new InMemoryStoreBackend(new VirtualClock());
        $this->logger = new RecordingLogger();
    }

    public function testSavedOverridesLoadBack(): void
    {
        $store = $this->createStore();
        $paused = self::createOverride('heating', true);
        $resumed = self::createOverride('lights', false);

        self::assertSame(AppPauseOverridePersistence::Stored, $store->saveOverride($paused));
        self::assertSame(AppPauseOverridePersistence::Stored, $store->saveOverride($resumed));

        $loaded = $store->loadOverrides();
        self::assertEquals($paused, $loaded->find(new AppId('heating')));
        self::assertEquals($resumed, $loaded->find(new AppId('lights')));
    }

    public function testOverrideKeepsItsKeyAndJsonShape(): void
    {
        $this->createStore()->saveOverride(self::createOverride('heating', true));

        self::assertSame(
            '{"paused":true,"since":"' . self::SINCE . '","source":"control"}',
            $this->backend->read('stewart:runtime:app-pause:heating'),
        );
    }

    public function testRemovedOverrideNoLongerLoads(): void
    {
        $store = $this->createStore();
        $store->saveOverride(self::createOverride('heating', true));

        self::assertSame(AppPauseOverridePersistence::Stored, $store->removeOverride(new AppId('heating')));
        self::assertSame(0, $store->loadOverrides()->count());
    }

    public function testWithoutStoreNothingIsPersisted(): void
    {
        $store = new AppPauseOverrideStore(new AppPauseOverrideCodec(AppPauseOverrideCodec::createOverrideWireMapper()), new NullLogger());

        self::assertSame(AppPauseOverridePersistence::NotConfigured, $store->saveOverride(self::createOverride('heating', true)));
        self::assertSame(AppPauseOverridePersistence::NotConfigured, $store->removeOverride(new AppId('heating')));
        self::assertSame(0, $store->loadOverrides()->count());
    }

    public function testFailedWriteIsReportedAndLogged(): void
    {
        $this->backend->simulateOutage('down');

        self::assertSame(AppPauseOverridePersistence::Failed, $this->createStore()->saveOverride(self::createOverride('heating', true)));
        self::assertSame(['Could not store the pause override'], $this->logger->listMessagesAt(LogLevel::WARNING));
    }

    public function testFailedLoadThrows(): void
    {
        $this->backend->simulateOutage('down');

        $this->assertThrowsReason(StoreError::Unreachable, fn() => $this->createStore()->loadOverrides());
    }

    public function testUnreadableOverridesAreSkipped(): void
    {
        $store = $this->createStore();
        $store->saveOverride(self::createOverride('heating', true));
        $this->backend->write('stewart:runtime:app-pause:lights', '{"paused":"yes"}', null);
        $this->backend->write('stewart:runtime:app-pause:Bad.Id', '{}', null);

        self::assertSame(['heating'], $store->loadOverrides()->mapToList(static fn(AppPauseOverride $override): string => $override->appId->value));
        self::assertCount(2, $this->logger->listMessagesAt(LogLevel::WARNING));
    }

    public function testOtherKeyspacesAreIgnored(): void
    {
        $this->backend->write('stewart:app:heating:app-pause:lights', '{}', null);
        $this->backend->write('upstairs:runtime:app-pause:lights', '{}', null);

        self::assertSame(0, $this->createStore()->loadOverrides()->count());
    }

    private function createStore(): AppPauseOverrideStore
    {
        return new AppPauseOverrideStore(
            new AppPauseOverrideCodec(AppPauseOverrideCodec::createOverrideWireMapper()),
            $this->logger,
            $this->backend,
            new StorePrefix('stewart'),
        );
    }

    private static function createOverride(string $appId, bool $paused): AppPauseOverride
    {
        return new AppPauseOverride(new AppId($appId), $paused, Instant::fromIso(self::SINCE), AppPauseSource::Control);
    }
}
