<?php

declare(strict_types=1);

namespace Stewart\Store\Tests\Unit\Collection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Store\Collection\StoreBackendFactoryCollection;
use Stewart\Store\Tests\Fixtures\SchemeOnlyBackendFactory;

#[CoversClass(StoreBackendFactoryCollection::class)]
final class StoreBackendFactoryCollectionTest extends TestCase
{
    public function testKeysEveryListedSchemeInLowerCase(): void
    {
        $redis = new SchemeOnlyBackendFactory('Redis', 'unix');
        $factories = StoreBackendFactoryCollection::keyedByScheme([$redis]);

        self::assertSame(['redis', 'unix'], $factories->listSchemes());
        self::assertSame($redis, $factories->find('REDIS'));
        self::assertNull($factories->find('sqlite'));
    }
}
