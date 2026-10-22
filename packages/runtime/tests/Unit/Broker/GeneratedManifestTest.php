<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Broker\GeneratedManifest;
use Stewart\Runtime\Broker\ManifestDrift;
use Stewart\Runtime\Config\CodegenConfig;
use Stewart\Runtime\Tests\Fixtures\Broker\UnversionedManifest;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigFixture;
use Stewart\Runtime\Tests\Fixtures\Generated\Code\Manifest;
use Stewart\Support\Wire\GeneratedFormat;

#[CoversClass(GeneratedManifest::class)]
#[CoversClass(ManifestDrift::class)]
final class GeneratedManifestTest extends TestCase
{
    public function testNoDriftIsEmpty(): void
    {
        self::assertTrue(GeneratedManifest::readFromClass(Manifest::class)->findDrift(Manifest::listEntityIds())->isEmpty());
    }

    public function testBothDirectionsAreReported(): void
    {
        $live = [...\array_slice(Manifest::listEntityIds(), 1), 'light.landing'];

        $drift = GeneratedManifest::readFromClass(Manifest::class)->findDrift($live);

        self::assertSame(['light.landing'], $drift->added);
        self::assertSame([Manifest::listEntityIds()[0]], $drift->removed);
    }

    public function testManifestWithoutVersionIsFormatZero(): void
    {
        $manifest = GeneratedManifest::readFromClass(UnversionedManifest::class);

        self::assertSame(0, $manifest->formatVersion);
        self::assertFalse($manifest->hasCurrentFormat());
    }

    public function testGeneratedManifestHasCurrentFormat(): void
    {
        $manifest = GeneratedManifest::readFromClass(Manifest::class);

        self::assertSame(GeneratedFormat::VERSION, $manifest->formatVersion);
        self::assertTrue($manifest->hasCurrentFormat());
    }

    public function testNamespaceWithoutGeneratedCodeHasNoManifest(): void
    {
        self::assertNull(GeneratedManifest::findForGeneratedCode(self::createCodegenConfig('App\\Nowhere')));
    }

    private static function createCodegenConfig(string $namespace): CodegenConfig
    {
        return ConfigFixture::createStewartConfig(['codegen' => ['namespace' => $namespace]])->codegen;
    }
}
