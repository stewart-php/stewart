<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Persistence\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\App\AppPauseOverride;
use Stewart\Runtime\Broker\AppPauseOverrideCodec;
use Stewart\Runtime\Broker\AppPauseOverrideStore;
use Stewart\Runtime\Lifecycle\AppPauseOverridePersistence;
use Stewart\Runtime\Lifecycle\AppPauseSource;
use Stewart\Store\Redis\RedisStoreBackendFactory;
use Stewart\Store\StoreBackend;
use Stewart\Store\StoreDsn;
use Stewart\Store\StorePrefix;
use Stewart\Store\StoreTiming;
use Stewart\Support\Time\RevoltTimers;

#[CoversClass(AppPauseOverrideStore::class)]
final class AppPauseOverrideStoreTest extends TestCase
{
    private StoreBackend $backend;

    private StorePrefix $prefix;

    protected function setUp(): void
    {
        $this->prefix = new StorePrefix('stewart-test-' . bin2hex(random_bytes(6)));
        $this->backend = new RedisStoreBackendFactory(new RevoltTimers())->createBackend(self::getStoreDsn(), new StoreTiming(Duration::seconds(2), Duration::seconds(2)));
    }

    protected function tearDown(): void
    {
        $this->backend->removeByPrefix($this->prefix->value . ':');
    }

    public function testOverridesRoundTripThroughValkey(): void
    {
        $paused = new AppPauseOverride(new AppId('heating'), true, Instant::fromIso('2026-10-06T08:00:00.250000Z'), AppPauseSource::Control);
        $resumed = new AppPauseOverride(new AppId('lights'), false, Instant::fromIso('2026-10-06T09:00:00Z'), AppPauseSource::Control);
        $store = $this->createStore();

        self::assertSame(AppPauseOverridePersistence::Stored, $store->saveOverride($paused));
        self::assertSame(AppPauseOverridePersistence::Stored, $store->saveOverride($resumed));
        self::assertSame(AppPauseOverridePersistence::Stored, $store->removeOverride(new AppId('lights')));

        $loaded = $this->createStore()->loadOverrides();
        self::assertSame(1, $loaded->count());
        self::assertEquals($paused, $loaded->find(new AppId('heating')));
        self::assertNotNull($this->backend->read($this->prefix->value . ':runtime:app-pause:heating'));
    }

    private function createStore(): AppPauseOverrideStore
    {
        return new AppPauseOverrideStore(new AppPauseOverrideCodec(AppPauseOverrideCodec::createOverrideWireMapper()), new NullLogger(), $this->backend, $this->prefix);
    }

    private static function getStoreDsn(): StoreDsn
    {
        $url = getenv('TEST_STORE_URL');

        return StoreDsn::parse($url === false || $url === '' ? 'redis://valkey:6379/15' : $url);
    }
}
