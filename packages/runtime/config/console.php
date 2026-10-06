<?php

declare(strict_types=1);

use Stewart\Runtime\App\AppCatalogResolver;
use Stewart\Runtime\App\AppDiscovery;
use Stewart\Runtime\Config\Environment\LeafParser;
use Stewart\Runtime\Config\Environment\LeafParserChain;
use Stewart\Runtime\Config\EnvironmentVariables;
use Stewart\Runtime\Kernel\BrokerKernel;
use Stewart\Runtime\Kernel\ProfileCompiler;
use Stewart\Runtime\Logging\LoggerFactory;
use Stewart\Support\Text\ClosestNameFinder;
use Stewart\Support\Time\Deadlines;
use Stewart\Support\Time\RevoltTimers;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\DependencyInjection\AddConsoleCommandPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();

    $services->instanceof(Command::class)->tag('console.command');
    $services->instanceof(LeafParser::class)->tag('stewart.config_leaf_parser');

    $services->load('Stewart\\Runtime\\Console\\', '../src/Console/');
    $services->load('Stewart\\Runtime\\Config\\', '../src/Config/');
    $services->load('Stewart\\Runtime\\Control\\Client\\', '../src/Control/Client/');
    $services->load('Stewart\\Runtime\\Health\\', '../src/Health/');

    $services->set(ClosestNameFinder::class);
    $services->set(EnvironmentVariables::class)->factory([EnvironmentVariables::class, 'fromGlobals']);
    $services->set(LeafParserChain::class)->arg('$leafParsers', tagged_iterator('stewart.config_leaf_parser'));
    $services->set(AppDiscovery::class);
    $services->set(AppCatalogResolver::class);
    $services->set(LoggerFactory::class);
    $services->set(RevoltTimers::class);
    $services->alias(Deadlines::class, RevoltTimers::class);

    $services->set(ProfileCompiler::class);
    $services->set(BrokerKernel::class);

    $builder->addCompilerPass(new AddConsoleCommandPass());
};
