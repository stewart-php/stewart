<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Reload;

use JsonException;
use Stewart\Runtime\Broker\DaemonLaunchOptions;
use Stewart\Runtime\Config\ConfigLoader;
use Stewart\Runtime\Config\ProjectRoot;
use Stewart\Support\Json\JsonDecoder;

final readonly class SourcePathFinder
{
    private const string COMPOSER_FILE = 'composer.json';

    // The container's environment and the daemon's own dependencies come from these, so only a restart applies them.
    private const array RESTART_ONLY_FILES = ['.env', 'composer.lock'];

    public function __construct(
        private ProjectRoot $projectRoot,
        private ConfigLoader $configs,
        private DaemonLaunchOptions $launch,
        private string $userServicesFile,
    ) {}

    public function findSourcePaths(): SourcePaths
    {
        return new SourcePaths(
            [...$this->listAutoloadDirectories(), $this->userServicesFile, $this->configs->locateConfigFile($this->launch->configFile)],
            array_map($this->projectRoot->resolvePath(...), self::RESTART_ONLY_FILES),
        );
    }

    // The project's own autoload roots, without autoload-dev, so editing a test reloads nothing.
    /** @return list<string> */
    private function listAutoloadDirectories(): array
    {
        $directories = [];

        foreach ($this->readAutoloadRoots() as $paths) {
            foreach (\is_array($paths) ? $paths : [$paths] as $path) {
                $directory = \is_string($path) ? rtrim($this->projectRoot->resolvePath($path), '/') : '';

                if ($directory !== '' && is_dir($directory)) {
                    $directories[] = $directory;
                }
            }
        }

        return array_values(array_unique($directories));
    }

    /** @return array<array-key, mixed> */
    private function readAutoloadRoots(): array
    {
        $file = $this->projectRoot->resolvePath(self::COMPOSER_FILE);
        $json = is_file($file) ? file_get_contents($file) : false;

        if ($json === false) {
            return [];
        }

        try {
            $composer = JsonDecoder::decodeJson($json);
        } catch (JsonException) {
            return [];
        }

        $autoload = \is_array($composer) ? $composer['autoload'] ?? null : null;
        $roots = \is_array($autoload) ? $autoload['psr-4'] ?? null : null;

        return \is_array($roots) ? $roots : [];
    }
}
