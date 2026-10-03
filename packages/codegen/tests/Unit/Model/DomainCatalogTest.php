<?php

declare(strict_types=1);

namespace Stewart\Codegen\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Codegen\Model\DomainCatalog;
use Stewart\Codegen\Model\DomainTraits;

#[CoversClass(DomainCatalog::class)]
#[CoversClass(DomainTraits::class)]
final class DomainCatalogTest extends TestCase
{
    public function testOnOffDomainIsNotNumeric(): void
    {
        self::assertEquals(new DomainTraits(onOff: true, numeric: false), new DomainCatalog()->getTraitsForDomain('light'));
    }

    public function testNumericDomainIsNotOnOff(): void
    {
        self::assertEquals(new DomainTraits(onOff: false, numeric: true), new DomainCatalog()->getTraitsForDomain('sensor'));
    }

    public function testUnknownDomainHasNoTraits(): void
    {
        self::assertEquals(new DomainTraits(onOff: false, numeric: false), new DomainCatalog()->getTraitsForDomain('vacuum'));
    }
}
