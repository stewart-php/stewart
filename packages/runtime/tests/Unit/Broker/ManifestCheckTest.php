<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Generated\GeneratedFormat;
use Stewart\Runtime\Broker\ManifestCheck;
use Stewart\Runtime\Tests\Fixtures\Broker\UnversionedManifest;
use Stewart\Runtime\Tests\Fixtures\Generated\Code\Manifest;

#[CoversClass(ManifestCheck::class)]
final class ManifestCheckTest extends TestCase
{
    public function testNoDriftIsReportedAsNothingToSay(): void
    {
        self::assertNull(new ManifestCheck(Manifest::class)->findDrift(Manifest::listEntityIds()));
    }

    public function testBothDirectionsAreReported(): void
    {
        $live = [...\array_slice(Manifest::listEntityIds(), 1), 'light.landing'];

        $drift = new ManifestCheck(Manifest::class)->findDrift($live);

        self::assertNotNull($drift);
        self::assertSame(['light.landing'], $drift->added);
        self::assertSame([Manifest::listEntityIds()[0]], $drift->removed);
    }

    public function testManifestWithoutVersionIsFormatZero(): void
    {
        $check = new ManifestCheck(UnversionedManifest::class);

        self::assertSame(0, $check->readFormatVersion());
        self::assertFalse($check->hasCurrentFormat());
    }

    public function testGeneratedManifestHasCurrentFormat(): void
    {
        $check = new ManifestCheck(Manifest::class);

        self::assertSame(GeneratedFormat::VERSION, $check->readFormatVersion());
        self::assertTrue($check->hasCurrentFormat());
    }
}
