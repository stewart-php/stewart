<?php

declare(strict_types=1);

namespace Stewart\Codegen\Tests\Fixtures;

use Stewart\Codegen\Attribute\AttributeFilter;
use Stewart\Codegen\Attribute\Collection\AttributeFilterCollection;
use Stewart\Codegen\Attribute\KeyPatterns;
use Stewart\Codegen\Entity\EntityFilter;
use Stewart\Codegen\GenerationOptions;
use Stewart\Codegen\GenerationTarget;
use Stewart\Codegen\Generator;
use Stewart\Codegen\Snapshot\Snapshot;
use Stewart\Codegen\Snapshot\SnapshotFileReader;
use Stewart\Runtime\Container\TypedContainer;
use Stewart\Runtime\Kernel\ConsoleKernel;

final class GoldenSnapshot
{
    public const string NAMESPACE = 'Stewart\Codegen\Tests\Fixtures\Expected';

    private static ?TypedContainer $container = null;

    private function __construct() {}

    public static function getSnapshotPath(): string
    {
        return __DIR__ . '/snapshot.json';
    }

    public static function getExpectedDirectory(): string
    {
        return __DIR__ . '/Expected';
    }

    public static function loadSnapshot(): Snapshot
    {
        return self::readSnapshotFile(self::getSnapshotPath());
    }

    public static function readSnapshotFile(string $path): Snapshot
    {
        return self::compileConsoleContainer()->resolveService(SnapshotFileReader::class)->readSnapshot($path);
    }

    public static function resolveGenerator(): Generator
    {
        return self::compileConsoleContainer()->resolveService(Generator::class);
    }

    public static function createGenerationRun(?string $directory = null): GenerationRun
    {
        return new GenerationRun(
            self::resolveGenerator(),
            new GenerationTarget(self::NAMESPACE, $directory ?? self::getExpectedDirectory()),
            new GenerationOptions(
                EntityFilter::fromPatterns(['*'], ['light.debug_*']),
                AttributeFilterCollection::keyedByDomain([new AttributeFilter('sensor', new KeyPatterns(['battery']))]),
            ),
        );
    }

    private static function compileConsoleContainer(): TypedContainer
    {
        return self::$container ??= new ConsoleKernel(__DIR__, [])->compileContainer();
    }
}
