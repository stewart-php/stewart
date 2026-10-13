<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Ipc\Message\Bootstrap;
use Stewart\Runtime\Ipc\StoreSettings;
use Stewart\Runtime\Tests\Fixtures\Ipc\TestBootstrap;
use Stewart\Runtime\Tests\Fixtures\Store\InMemoryStoreBackendFactory;
use Stewart\Runtime\Time\SystemClock;
use Stewart\Runtime\Worker\DsnStoreBackendOpener;
use Stewart\Store\Exception\StoreSetupError;
use Stewart\Store\GuardedStoreBackend;
use Stewart\Store\StoreBackendConnector;
use Stewart\Store\StoreBackendRegistry;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(DsnStoreBackendOpener::class)]
final class DsnStoreBackendOpenerTest extends TestCase
{
    use AssertsReason;

    public function testBackendForDsnSchemeOpens(): void
    {
        $factory = new InMemoryStoreBackendFactory();

        $backend = $this->createOpener($factory)->openForBootstrap($this->createBootstrap('memory://local'));

        self::assertInstanceOf(GuardedStoreBackend::class, $backend);
        self::assertSame(1, $factory->opened);
    }

    public function testSchemeWithoutBackendIsRefused(): void
    {
        $this->assertThrowsReason(StoreSetupError::SchemeUnsupported, fn() => $this->createOpener(new InMemoryStoreBackendFactory())->openForBootstrap($this->createBootstrap('redis://valkey:6379/0')));
    }

    public function testNoStoreSettingsOpenNothing(): void
    {
        self::assertNull($this->createOpener(new InMemoryStoreBackendFactory())->openForBootstrap(TestBootstrap::createForApps([])));
    }

    private function createOpener(InMemoryStoreBackendFactory $factory): DsnStoreBackendOpener
    {
        return new DsnStoreBackendOpener(new StoreBackendConnector(new StoreBackendRegistry([$factory]), SystemClock::inUtc()));
    }

    private function createBootstrap(string $dsn): Bootstrap
    {
        return TestBootstrap::createForApps([], store: new StoreSettings($dsn, 'stewart', Duration::seconds(1), Duration::seconds(1)));
    }
}
