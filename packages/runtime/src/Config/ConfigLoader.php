<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Stewart\Contracts\Exception\IdentifierException;
use Stewart\Runtime\Exception\ConfigurationError;
use Stewart\Runtime\Exception\ConfigurationException;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final readonly class ConfigLoader
{
    private const string MISSING_FILE_FINGERPRINT = 'missing';

    private const string DEFAULT_CONFIG_FILE = 'stewart.yaml';

    public function __construct(
        private EnvironmentPlaceholders $placeholders,
        private EnvironmentOverlay $overlay,
        private ConfigFileReader $files,
        private ProjectRoot $projectRoot,
        private StewartConfigSchema $configuration,
        private RenamedSettings $renamedSettings,
        private GeneratedNamespaceResolver $generatedNamespaces,
    ) {}

    /**
     * @param array<string, mixed> $cliOverrides
     * @return array<array-key, mixed>
     * @throws ConfigurationException
     */
    public function mergeConfigTree(?string $path, array $cliOverrides = []): array
    {
        $tree = $this->configuration->buildConfigTree();
        $fileConfig = $this->placeholders->resolve($tree, $this->readConfigFile($path));
        $this->renamedSettings->refuseRenamedKeys($fileConfig);
        $configs = [$fileConfig, $this->overlay->collectEnvironmentOverrides($tree, $fileConfig)];

        if ($cliOverrides !== []) {
            $configs[] = $cliOverrides;
        }

        try {
            return new Processor()->process($tree, $configs);
        } catch (InvalidConfigurationException $e) {
            throw ConfigurationException::createForReason(ConfigurationError::SchemaViolation, $e);
        }
    }

    /**
     * @param array<string, mixed> $cliOverrides
     * @throws ConfigurationException
     * @throws IdentifierException
     */
    public function loadConfig(?string $path, array $cliOverrides = []): StewartConfig
    {
        return StewartConfig::fromSection(ConfigSection::forRoot($this->mergeConfigTree($path, $cliOverrides)), $this->generatedNamespaces);
    }

    /**
     * @return array<array-key, mixed>
     * @throws ConfigurationException
     * @throws IdentifierException
     */
    public function buildValidatedConfigTree(?string $path): array
    {
        $tree = $this->mergeConfigTree($path);
        StewartConfig::fromSection(ConfigSection::forRoot($tree), $this->generatedNamespaces);

        return $tree;
    }

    public function locateConfigFile(?string $path): string
    {
        return $path ?? $this->projectRoot->resolvePath(self::DEFAULT_CONFIG_FILE);
    }

    public function fingerprintConfigFile(?string $path): string
    {
        $file = $this->locateConfigFile($path);

        return is_file($file) ? (string) hash_file('xxh128', $file) : self::MISSING_FILE_FINGERPRINT;
    }

    public function findMissingDefaultFile(?string $path): ?string
    {
        $defaultFile = $this->locateConfigFile(null);

        return $path === null && !is_file($defaultFile) ? $defaultFile : null;
    }

    /**
     * @return array<array-key, mixed>
     * @throws ConfigurationException
     */
    private function readConfigFile(?string $path): array
    {
        // Container setups may configure everything through the environment.
        return $this->findMissingDefaultFile($path) !== null ? [] : $this->files->readYamlMap($this->locateConfigFile($path));
    }
}
