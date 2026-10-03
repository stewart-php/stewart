<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Config;

use Stewart\Runtime\Config\ConfigFileReader;
use Stewart\Runtime\Config\ConfigLoader;
use Stewart\Runtime\Config\Environment\BooleanLeafParser;
use Stewart\Runtime\Config\Environment\FloatLeafParser;
use Stewart\Runtime\Config\Environment\InlineListLeafParser;
use Stewart\Runtime\Config\Environment\InlineValueInference;
use Stewart\Runtime\Config\Environment\IntegerLeafParser;
use Stewart\Runtime\Config\Environment\LeafParserChain;
use Stewart\Runtime\Config\Environment\ScalarLeafParser;
use Stewart\Runtime\Config\Environment\VariableLeafParser;
use Stewart\Runtime\Config\EnvironmentOverlay;
use Stewart\Runtime\Config\EnvironmentPlaceholders;
use Stewart\Runtime\Config\EnvironmentVariables;
use Stewart\Runtime\Config\ProjectRoot;
use Stewart\Runtime\Config\SecretFileReader;
use Stewart\Runtime\Config\StewartConfigSchema;
use Stewart\Runtime\Console\ConfigDumpCommand;
use Stewart\Runtime\Kernel\ConsoleKernel;
use Stewart\Runtime\Kernel\SyntheticServices;
use Stewart\Support\Text\ClosestNameFinder;
use Symfony\Component\Console\Tester\CommandTester;

final class ConfigLoaderFixture
{
    private function __construct() {}

    public static function createLoader(
        EnvironmentVariables $variables = new EnvironmentVariables(),
        ProjectRoot $projectRoot = new ProjectRoot('/nonexistent'),
    ): ConfigLoader {
        return new ConfigLoader(self::createPlaceholders($variables), self::createOverlay($variables), new ConfigFileReader(), $projectRoot, new StewartConfigSchema());
    }

    public static function createOverlay(EnvironmentVariables $variables = new EnvironmentVariables()): EnvironmentOverlay
    {
        return new EnvironmentOverlay($variables, self::createLeafParserChain(), new ClosestNameFinder(), new SecretFileReader());
    }

    public static function createPlaceholders(EnvironmentVariables $variables = new EnvironmentVariables()): EnvironmentPlaceholders
    {
        return new EnvironmentPlaceholders($variables, self::createLeafParserChain());
    }

    public static function createConsoleConfigDump(string $projectDir, EnvironmentVariables $variables): CommandTester
    {
        $kernel = new ConsoleKernel($projectDir, [], new SyntheticServices()->withService(EnvironmentVariables::class, $variables));

        return new CommandTester($kernel->createApplication()->find(ConfigDumpCommand::NAME));
    }

    public static function createLeafParserChain(): LeafParserChain
    {
        $inference = new InlineValueInference();

        return new LeafParserChain([
            new BooleanLeafParser(),
            new IntegerLeafParser(),
            new FloatLeafParser(),
            new ScalarLeafParser(),
            new VariableLeafParser($inference),
            new InlineListLeafParser($inference),
        ]);
    }
}
