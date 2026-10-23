<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Config;

use Stewart\Runtime\Config\ConfigFileReader;
use Stewart\Runtime\Config\ConfigLoader;
use Stewart\Runtime\Config\Environment\EnvironmentValueParser;
use Stewart\Runtime\Config\EnvironmentOverlay;
use Stewart\Runtime\Config\EnvironmentPlaceholders;
use Stewart\Runtime\Config\EnvironmentVariables;
use Stewart\Runtime\Config\ProjectRoot;
use Stewart\Runtime\Config\RenamedSettings;
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
        return new ConfigLoader(self::createPlaceholders($variables), self::createOverlay($variables), new ConfigFileReader(), $projectRoot, new StewartConfigSchema(), new RenamedSettings());
    }

    public static function createOverlay(EnvironmentVariables $variables = new EnvironmentVariables()): EnvironmentOverlay
    {
        return new EnvironmentOverlay($variables, new EnvironmentValueParser(), new ClosestNameFinder(), new SecretFileReader(), new RenamedSettings());
    }

    public static function createPlaceholders(EnvironmentVariables $variables = new EnvironmentVariables()): EnvironmentPlaceholders
    {
        return new EnvironmentPlaceholders($variables, new EnvironmentValueParser());
    }

    public static function createConsoleConfigDump(string $projectDir, EnvironmentVariables $variables): CommandTester
    {
        $kernel = new ConsoleKernel($projectDir, [], new SyntheticServices()->withService(EnvironmentVariables::class, $variables));

        return new CommandTester($kernel->createApplication()->find(ConfigDumpCommand::NAME));
    }
}
