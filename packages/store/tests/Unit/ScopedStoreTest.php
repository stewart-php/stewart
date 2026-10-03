<?php

declare(strict_types=1);

namespace Stewart\Store\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\StoreError;
use Stewart\Contracts\Time\Duration;
use Stewart\Store\Keyspace;
use Stewart\Store\ScopedStore;
use Stewart\Store\StorePrefix;
use Stewart\Store\StoreValueCodec;
use Stewart\Store\Tests\Fixtures\Thermostat;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Store\InMemoryStoreBackend;
use Stewart\Testing\Time\ManualTimers;

#[CoversClass(ScopedStore::class)]
final class ScopedStoreTest extends TestCase
{
    use AssertsReason;

    private ManualTimers $timers;

    private InMemoryStoreBackend $backend;

    private ScopedStore $store;

    protected function setUp(): void
    {
        $this->timers = new ManualTimers();
        $this->backend = new InMemoryStoreBackend($this->timers->clock);
        $this->store = $this->createScopedStore(Keyspace::forApp(new StorePrefix('stewart'), new AppId('heating')));
    }

    public function testMissingKeyReadsNull(): void
    {
        self::assertFalse($this->store->has('absent'));
        self::assertNull($this->store->getInt('absent'));
        self::assertNull($this->store->getFloat('absent'));
        self::assertNull($this->store->getString('absent'));
        self::assertNull($this->store->getBool('absent'));
        self::assertNull($this->store->getObject('absent', Thermostat::class));
    }

    public function testWritesRoundTripWithType(): void
    {
        $this->store->set('count', 3);
        $this->store->set('target', 21.5);
        $this->store->set('mode', 'away');
        $this->store->set('armed', true);
        $this->store->set('thermostat', new Thermostat(19.0, 'eco'));

        self::assertSame(3, $this->store->getInt('count'));
        self::assertSame(21.5, $this->store->getFloat('target'));
        self::assertSame('away', $this->store->getString('mode'));
        self::assertTrue($this->store->getBool('armed'));
        self::assertSame(19.0, $this->store->getObject('thermostat', Thermostat::class)?->target);
    }

    public function testAppsAreIsolated(): void
    {
        $this->store->set('mode', 'away');
        $lights = $this->createScopedStore(Keyspace::forApp(new StorePrefix('stewart'), new AppId('lights')));

        self::assertNull($lights->getString('mode'));
        self::assertSame([], $lights->listKeys());
    }

    public function testGlobalBucketIsShared(): void
    {
        $this->createScopedStore(Keyspace::forGlobalScope(new StorePrefix('stewart')))->set('house_mode', 'night');

        self::assertSame('night', $this->createScopedStore(Keyspace::forGlobalScope(new StorePrefix('stewart')))->getString('house_mode'));
        self::assertNull($this->store->getString('house_mode'));
    }

    public function testKeysAreSortedWithoutPrefix(): void
    {
        $this->store->set('mode', 'away');
        $this->store->set('count', 1);
        $this->store->set('armed', true);

        self::assertSame(['armed', 'count', 'mode'], $this->store->listKeys());
    }

    public function testClearLeavesOtherScopes(): void
    {
        $this->store->set('mode', 'away');
        $global = $this->createScopedStore(Keyspace::forGlobalScope(new StorePrefix('stewart')));
        $global->set('house_mode', 'night');

        $this->store->clear();

        self::assertSame([], $this->store->listKeys());
        self::assertSame('night', $global->getString('house_mode'));
    }

    public function testDeletingMissingKeyIsNoError(): void
    {
        $this->store->delete('absent');

        $this->addToAssertionCount(1);
    }

    public function testTtlExpiresValue(): void
    {
        $this->store->set('seen', true, Duration::minutes(10));

        $this->timers->delay(Duration::minutes(9));
        self::assertTrue($this->store->getBool('seen'));

        $this->timers->delay(Duration::minutes(1));
        self::assertNull($this->store->getBool('seen'));
        self::assertSame([], $this->store->listKeys());
    }

    public function testRewriteWithoutTtlPersists(): void
    {
        $this->store->set('seen', true, Duration::minutes(10));
        $this->store->set('seen', false);

        $this->timers->delay(Duration::hours(1));

        self::assertFalse($this->store->getBool('seen'));
    }

    public function testIncrementKeepsExpiry(): void
    {
        self::assertSame(1, $this->store->increment('opens'));
        self::assertSame(3, $this->store->increment('opens', 2));
        self::assertSame(3, $this->store->getInt('opens'));

        $this->store->set('recent', 1, Duration::minutes(5));
        $this->store->increment('recent');
        $this->timers->delay(Duration::minutes(6));

        self::assertNull($this->store->getInt('recent'));
    }

    public function testIncrementingNonNumberFails(): void
    {
        $this->store->set('mode', 'away');

        $e = $this->assertThrowsReason(StoreError::NotIncrementable, fn() => $this->store->increment('mode'));

        self::assertStringContainsString('Key "mode" cannot be incremented: the value is not a whole number', $e->getMessage());
    }

    public function testRejectsUnusableKeyBeforeBackend(): void
    {
        $this->backend->simulateOutage('nothing should reach me');

        $this->assertThrowsReason(StoreError::KeyInvalid, fn() => $this->store->set('with space', 1));
    }

    public function testBackendFailurePassesThrough(): void
    {
        $this->backend->simulateOutage('connection reset');

        $e = $this->assertThrowsReason(StoreError::Unreachable, fn() => $this->store->getString('mode'));

        self::assertStringContainsString('connection reset', $e->getMessage());
    }

    private function createScopedStore(Keyspace $keyspace): ScopedStore
    {
        return new ScopedStore($this->backend, $keyspace, new StoreValueCodec());
    }
}
