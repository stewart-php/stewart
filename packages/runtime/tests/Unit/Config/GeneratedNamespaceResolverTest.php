<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Config\CodegenConfig;
use Stewart\Runtime\Config\ConfigSection;
use Stewart\Runtime\Config\GeneratedNamespaceResolver;
use Stewart\Runtime\Config\ProjectDirectory;
use Stewart\Runtime\Config\ProjectRoot;
use Stewart\Runtime\Config\Psr4Map;
use Stewart\Runtime\Exception\ConfigurationError;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(GeneratedNamespaceResolver::class)]
#[CoversClass(Psr4Map::class)]
#[CoversClass(CodegenConfig::class)]
final class GeneratedNamespaceResolverTest extends TestCase
{
    use AssertsReason;

    private const string PROJECT = '/srv/home';

    public function testNamespaceComposerMapsToTheOutputDirectoryIsUsed(): void
    {
        $resolver = self::createResolver(['Automations\\' => [self::PROJECT . '/apps'], 'Automations\\Generated\\' => [self::PROJECT . '/generated']]);

        self::assertSame('Automations\\Generated', $resolver->resolveNamespaceFor(new ProjectDirectory('generated'))->value);
    }

    public function testComposerPathsWithDotSegmentsStillMatch(): void
    {
        $resolver = self::createResolver(['App\\Generated\\' => [self::PROJECT . '/vendor/composer/../../generated']]);

        self::assertSame('App\\Generated', $resolver->resolveNamespaceFor(new ProjectDirectory('generated'))->value);
    }

    public function testUnmappedDirectoryGetsTheSkeletonNamespace(): void
    {
        self::assertSame('Automations\\Generated', self::createResolver([])->resolveNamespaceFor(new ProjectDirectory('generated'))->value);
    }

    public function testTwoNamespacesForOneDirectoryAreRefused(): void
    {
        $resolver = self::createResolver(['App\\Generated\\' => [self::PROJECT . '/generated'], 'Home\\Generated\\' => [self::PROJECT . '/generated']]);

        $this->assertThrowsReason(ConfigurationError::GeneratedNamespaceAmbiguous, static fn() => $resolver->resolveNamespaceFor(new ProjectDirectory('generated')));
    }

    public function testExplicitNamespaceWinsOverTheMapping(): void
    {
        $section = ConfigSection::forRoot(['codegen' => ['namespace' => 'Mine\\Classes', 'output_dir' => 'generated', 'include' => ['*'], 'exclude' => [], 'attributes' => []]])->readSection('codegen');
        $resolver = self::createResolver(['App\\Generated\\' => [self::PROJECT . '/generated']]);

        self::assertSame('Mine\\Classes', CodegenConfig::fromSection($section, $resolver)->namespace->value);
    }

    /** @param array<string, list<string>> $directoriesByNamespace */
    private static function createResolver(array $directoriesByNamespace): GeneratedNamespaceResolver
    {
        return new GeneratedNamespaceResolver(new Psr4Map($directoriesByNamespace), new ProjectRoot(self::PROJECT));
    }
}
