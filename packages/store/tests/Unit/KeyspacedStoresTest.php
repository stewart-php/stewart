<?php

declare(strict_types=1);

namespace Stewart\Store\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Exception\StoreError;
use Stewart\Contracts\Exception\StoreException;
use Stewart\Contracts\Store\Store;
use Stewart\Store\DisabledStores;
use Stewart\Store\Exception\StoreSetupError;
use Stewart\Store\Exception\StoreSetupException;
use Stewart\Store\KeyspacedStores;
use Stewart\Store\ReadOnlyStore;
use Stewart\Store\StorePrefix;
use Stewart\Store\StoreValueCodec;
use Stewart\Store\Tests\Fixtures\Thermostat;
use Stewart\Support\Text\ClosestNameFinder;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Store\InMemoryStoreBackend;
use Stewart\Testing\Time\VirtualClock;

#[CoversClass(KeyspacedStores::class)]
#[CoversClass(ReadOnlyStore::class)]
#[CoversClass(DisabledStores::class)]
#[CoversClass(StoreSetupException::class)]
final class KeyspacedStoresTest extends TestCase
{
    use AssertsReason;

    private KeyspacedStores $stores;

    protected function setUp(): void
    {
        $backend = new InMemoryStoreBackend(new VirtualClock());

        $this->stores = new KeyspacedStores($backend, new StorePrefix('stewart'), AppIdCollection::fromIds([new AppId('heating'), new AppId('boiler'), new AppId('lights')]), new StoreValueCodec(), new ClosestNameFinder());
    }

    public function testPeerSeesOwnerWrites(): void
    {
        $this->stores->openAppStore(new AppId('boiler'))->set('target', 60.0);

        self::assertSame(60.0, $this->stores->openPeerStoreForReading(new AppId('boiler'))->getFloat('target'));
    }

    public function testPeerStoreIsReadOnly(): void
    {
        self::assertNotInstanceOf(Store::class, $this->stores->openPeerStoreForReading(new AppId('boiler')));
    }

    public function testPeerExcludesGlobalScope(): void
    {
        $this->stores->openGlobalStore()->set('house_mode', 'night');

        self::assertNull($this->stores->openPeerStoreForReading(new AppId('boiler'))->getString('house_mode'));
    }

    public function testUnknownPeerSuggestsClosestId(): void
    {
        $e = $this->assertThrowsReason(StoreSetupError::UnknownPeerApp, fn() => $this->stores->openPeerStoreForReading(new AppId('boilr')));

        self::assertStringContainsString('No automation has ID "boilr", so its store cannot be opened. Did you mean "boiler"?', $e->getMessage());
    }

    public function testUnknownPeerWithoutSuggestion(): void
    {
        $e = $this->assertThrowsReason(StoreSetupError::UnknownPeerApp, fn() => $this->stores->openPeerStoreForReading(new AppId('greenhouse')));

        self::assertStringContainsString('No automation has ID "greenhouse", so its store cannot be opened.', $e->getMessage());
    }

    public function testDisabledAppCanBePeeked(): void
    {
        $stores = new KeyspacedStores(
            new InMemoryStoreBackend(new VirtualClock()),
            new StorePrefix('stewart'),
            AppIdCollection::fromIds([new AppId('heating'), new AppId('retired')]),
            new StoreValueCodec(),
            new ClosestNameFinder(),
        );

        $stores->openAppStore(new AppId('retired'))->set('thermostat', new Thermostat(18.0, 'off'));

        self::assertSame(18.0, $stores->openPeerStoreForReading(new AppId('retired'))->getObject('thermostat', Thermostat::class)?->target);
    }

    public function testDisabledStoresRefuseEveryOpen(): void
    {
        $stores = new DisabledStores();

        foreach ([static fn() => $stores->openAppStore(new AppId('heating')), static fn() => $stores->openGlobalStore(), static fn() => $stores->openPeerStoreForReading(new AppId('boiler'))] as $ask) {
            try {
                $ask();
                self::fail();
            } catch (StoreException $e) {
                self::assertSame(StoreError::NotConfigured, $e->reason);
            }
        }
    }
}
