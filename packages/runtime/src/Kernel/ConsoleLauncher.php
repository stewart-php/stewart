<?php

declare(strict_types=1);

namespace Stewart\Runtime\Kernel;

use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Dotenv\Exception\ExceptionInterface as DotenvException;

final readonly class ConsoleLauncher
{
    private const string ENVIRONMENT_FILE = '/.env';

    public function __construct(
        public string $projectDir,
        private ClassLoader $loader,
    ) {}

    public static function createForInstalledProject(ClassLoader $loader): self
    {
        $installPath = InstalledVersions::getRootPackage()['install_path'];

        return new self(realpath($installPath) ?: $installPath, $loader);
    }

    public function launchConsole(): int
    {
        try {
            $this->loadEnvironmentFile();
        } catch (DotenvException $e) {
            fwrite(\STDERR, \sprintf("Could not read %s: %s\n", $this->projectDir . self::ENVIRONMENT_FILE, $e->getMessage()));

            return 1;
        }

        return new ConsoleKernel($this->projectDir, $this->loader->getPrefixesPsr4())->createApplication()->run();
    }

    /** @throws DotenvException */
    public function loadEnvironmentFile(): void
    {
        $file = $this->projectDir . self::ENVIRONMENT_FILE;

        if (is_file($file)) {
            new Dotenv()->usePutenv()->load($file);
        }
    }
}
