<?php

declare(strict_types=1);

namespace Stewart\Runtime\Kernel;

use Composer\InstalledVersions;
use Stewart\Runtime\Config\ProjectRoot;
use Stewart\Runtime\Config\Psr4Map;
use Stewart\Runtime\Console\StewartApplication;
use Stewart\Runtime\Container\TypedContainer;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;

final readonly class ConsoleKernel
{
    private const string APPS_DIRECTORY = '/apps';

    private const string SERVICES_FILE = '/services.php';

    private const string RUNTIME_PACKAGE = 'stewart-php/runtime';

    private const string UNKNOWN_VERSION = 'unknown';

    /** @param array<string, array<int, string>> $psr4 */
    public function __construct(
        private string $projectDir,
        private array $psr4,
        private SyntheticServices $overrides = new SyntheticServices(),
    ) {}

    public function createApplication(): StewartApplication
    {
        $container = $this->compileContainer();

        $application = new StewartApplication($this->detectInstalledVersion());
        $application->setCommandLoader($container->resolveService(CommandLoaderInterface::class, 'console.command_loader'));

        return $application;
    }

    public function compileContainer(): TypedContainer
    {
        return new ProfileCompiler()->compileProfileContainer(ContainerProfile::Console, $this->buildSyntheticServicesForProject(), $this->buildNamedArgumentsForProject());
    }

    private function buildSyntheticServicesForProject(): SyntheticServices
    {
        return new SyntheticServices()
            ->withService(ProjectRoot::class, new ProjectRoot($this->projectDir))
            ->withService(Psr4Map::class, new Psr4Map($this->psr4))
            ->withOverrides($this->overrides);
    }

    private function buildNamedArgumentsForProject(): NamedArguments
    {
        return new NamedArguments()
            ->withArgument('daemonVersion', $this->detectInstalledVersion())
            ->withArgument('appsDirectory', $this->projectDir . self::APPS_DIRECTORY)
            ->withArgument('userServicesFile', $this->projectDir . self::SERVICES_FILE);
    }

    private function detectInstalledVersion(): string
    {
        return InstalledVersions::isInstalled(self::RUNTIME_PACKAGE)
            ? InstalledVersions::getPrettyVersion(self::RUNTIME_PACKAGE) ?? self::UNKNOWN_VERSION
            : self::UNKNOWN_VERSION;
    }
}
