<?php

declare(strict_types=1);

namespace Stewart\Store\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Store\Exception\StoreSetupError;
use Stewart\Store\GuardedStoreBackend;
use Stewart\Store\StoreBackendConnector;
use Stewart\Store\StoreBackendRegistry;
use Stewart\Store\StoreDsn;
use Stewart\Store\StoreTiming;
use Stewart\Store\Tests\Fixtures\InMemoryBackendFactory;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Time\VirtualClock;

#[CoversClass(StoreBackendConnector::class)]
final class StoreBackendConnectorTest extends TestCase
{
    use AssertsReason;

    public function testOpensBackendThroughFactoryForScheme(): void
    {
        $factory = new InMemoryBackendFactory();
        $dsn = StoreDsn::parse('memory://local');
        $timing = new StoreTiming(Duration::seconds(2), Duration::seconds(5));

        $backend = new StoreBackendConnector(new StoreBackendRegistry([$factory]), new VirtualClock())->connectToBackend($dsn, $timing);

        self::assertInstanceOf(GuardedStoreBackend::class, $backend);
        self::assertSame($dsn, $factory->openedDsn);
        self::assertSame($timing, $factory->openedTiming);
    }

    public function testRefusesSchemeNoBackendHandles(): void
    {
        $this->assertThrowsReason(StoreSetupError::SchemeUnsupported, fn() => new StoreBackendConnector(new StoreBackendRegistry([new InMemoryBackendFactory()]), new VirtualClock())
            ->connectToBackend(StoreDsn::parse('redis://valkey:6379/0'), new StoreTiming(Duration::seconds(1), Duration::seconds(1))));
    }

    public function testRefusesAnySchemeWithoutBackends(): void
    {
        $this->assertThrowsReason(StoreSetupError::SchemeUnsupported, fn() => new StoreBackendConnector(new StoreBackendRegistry(), new VirtualClock())
            ->connectToBackend(StoreDsn::parse('memory://local'), new StoreTiming(Duration::seconds(1), Duration::seconds(1))));
    }
}
