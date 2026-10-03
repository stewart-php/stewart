<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Stewart\Contracts\Exception\IdentifierException;
use Stewart\Runtime\Exception\ConfigurationException;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final readonly class ConfigLoader
{
    private const string DEFAULT_CONFIG_FILE = 'stewart.yaml';

    public function __construct(
        private EnvironmentPlaceholders $placeholders,
        private EnvironmentOverlay $overlay,
        private ConfigFileReader $files,
        private ProjectRoot $projectRoot,
        private StewartConfigSchema $configuration,
    ) {}

    /**
     * @param array<string, mixed> $cliOverrides
     * @return array<array-key, mixed>
     * @throws ConfigurationException
     */
    public function mergeConfigTree(?string $path, array $cliOverrides = []): array
    {
        $tree = $this->configuration->buildConfigTree();
        $fileConfig = $this->placeholders->resolve($tree, $this->files->readYamlMap($this->locateConfigFile($path)));
        $configs = [$fileConfig, $this->overlay->collectEnvironmentOverrides($tree, $fileConfig)];

        if ($cliOverrides !== []) {
            $configs[] = $cliOverrides;
        }

        try {
            return new Processor()->process($tree, $configs);
        } catch (InvalidConfigurationException $e) {
            throw ConfigurationException::schemaViolation($e);
        }
    }

    /**
     * @param array<string, mixed> $cliOverrides
     * @throws ConfigurationException
     * @throws IdentifierException
     */
    public function loadConfig(?string $path, array $cliOverrides = []): StewartConfig
    {
        return StewartConfig::fromSection(ConfigSection::forRoot($this->mergeConfigTree($path, $cliOverrides)));
    }

    /**
     * @return array<array-key, mixed>
     * @throws ConfigurationException
     * @throws IdentifierException
     */
    public function buildValidatedConfigTree(?string $path): array
    {
        $tree = $this->mergeConfigTree($path);
        StewartConfig::fromSection(ConfigSection::forRoot($tree));

        return $tree;
    }

    public function locateConfigFile(?string $path): string
    {
        return $path ?? $this->projectRoot->resolvePath(self::DEFAULT_CONFIG_FILE);
    }
}
