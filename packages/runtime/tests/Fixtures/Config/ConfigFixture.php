<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Config;

use Stewart\Runtime\Config\ConfigSection;
use Stewart\Runtime\Config\ServiceCallPolicy;
use Stewart\Runtime\Config\StewartConfig;
use Stewart\Runtime\Config\StewartConfigSchema;
use Stewart\Runtime\Config\SupervisionConfig;
use Symfony\Component\Config\Definition\Processor;

final class ConfigFixture
{
    public const array HOME_ASSISTANT = ['url' => 'ws://home-assistant.invalid:8123', 'token' => 'test-token'];

    private function __construct() {}

    /** @param array<string, mixed> $yaml */
    public static function createStewartConfig(array $yaml = []): StewartConfig
    {
        return StewartConfig::fromSection(ConfigSection::forRoot(self::processConfigYaml($yaml)));
    }

    /**
     * @param array<string, mixed> $yaml
     * @return array<array-key, mixed>
     */
    private static function processConfigYaml(array $yaml = []): array
    {
        return new Processor()->process(new StewartConfigSchema()->buildConfigTree(), [['home_assistant' => self::HOME_ASSISTANT], $yaml]);
    }

    /** @param array<string, mixed> $supervision */
    public static function createSupervisionConfig(array $supervision = []): SupervisionConfig
    {
        return self::createStewartConfig(['supervision' => $supervision])->supervision;
    }

    /** @param array<string, mixed> $serviceCalls */
    public static function createServiceCallPolicy(array $serviceCalls = []): ServiceCallPolicy
    {
        return self::createStewartConfig(['service_calls' => $serviceCalls])->serviceCalls;
    }
}
