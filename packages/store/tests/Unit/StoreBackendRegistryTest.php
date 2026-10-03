<?php

declare(strict_types=1);

namespace Stewart\Store\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Store\Exception\StoreSetupError;
use Stewart\Store\Exception\StoreSetupException;
use Stewart\Store\StoreBackendRegistry;
use Stewart\Store\StoreDsn;
use Stewart\Store\Tests\Fixtures\SchemeOnlyBackendFactory;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(StoreBackendRegistry::class)]
#[CoversClass(StoreSetupException::class)]
final class StoreBackendRegistryTest extends TestCase
{
    use AssertsReason;

    public function testRegisteredSchemeResolvesToItsFactory(): void
    {
        $redis = new SchemeOnlyBackendFactory('redis');

        self::assertSame($redis, new StoreBackendRegistry([$redis])->requireFactoryForDsn(StoreDsn::parse('redis://valkey:6379/0')));
    }

    public function testUnsupportedSchemeListsTheRegisteredOnes(): void
    {
        $this->assertThrowsReason(StoreSetupError::SchemeUnsupported, fn() => new StoreBackendRegistry([new SchemeOnlyBackendFactory('redis')])->requireFactoryForDsn(StoreDsn::parse('sqlite://var/store.db')));
    }

    public function testFactoryResolvesForEveryListedScheme(): void
    {
        $factory = new SchemeOnlyBackendFactory('redis', 'unix');
        $registry = new StoreBackendRegistry([$factory]);

        self::assertSame($factory, $registry->requireFactoryForDsn(StoreDsn::parse('redis://valkey:6379/0')));
        self::assertSame($factory, $registry->requireFactoryForDsn(StoreDsn::parse('unix:///run/valkey.sock')));
    }
}
