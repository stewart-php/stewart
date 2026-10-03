<?php

declare(strict_types=1);

namespace Stewart\Runtime\Kernel;

use Composer\InstalledVersions;
use Stewart\Runtime\Container\TypedContainer;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final readonly class ProfileCompiler
{
    private const string CONFIG_DIRECTORY = __DIR__ . '/../../config';

    private const string PACKAGE_CONFIG = '/config/services.php';

    public function compileProfileContainer(ContainerProfile $profile, SyntheticServices $services, NamedArguments $arguments): TypedContainer
    {
        $builder = new ContainerBuilder();
        $loader = new PhpFileLoader($builder, new FileLocator(self::CONFIG_DIRECTORY));

        foreach ($profile->configFiles() as $file) {
            $loader->load($file);
        }

        foreach ($profile->optionalPackages() as $package) {
            $config = $this->configFileOfInstalledPackage($package);

            if ($config !== null) {
                $loader->load($config);
            }
        }

        $services->declareDefinitionsOn($builder);
        $builder->addCompilerPass(new NamedArgumentsPass($arguments), PassConfig::TYPE_BEFORE_OPTIMIZATION);
        $builder->compile();
        $services->setInstancesOn($builder);

        return new TypedContainer($builder);
    }

    private function configFileOfInstalledPackage(string $package): ?string
    {
        if (!InstalledVersions::isInstalled($package)) {
            return null;
        }

        $config = InstalledVersions::getInstallPath($package) . self::PACKAGE_CONFIG;

        return is_file($config) ? $config : null;
    }
}
