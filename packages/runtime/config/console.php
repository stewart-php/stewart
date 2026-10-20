<?php

declare(strict_types=1);

use Amp\Socket\DnsSocketConnector;
use Stewart\Runtime\App\AppCatalogResolver;
use Stewart\Runtime\App\AppDiscovery;
use Stewart\Runtime\Broker\AppPlacement;
use Stewart\Runtime\Broker\AppPlacementFactory;
use Stewart\Runtime\Broker\CpuCountDetector;
use Stewart\Runtime\Config\EnvironmentVariables;
use Stewart\Runtime\Control\Client\ControlClient;
use Stewart\Runtime\Health\ReadinessCheck;
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

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();

    $services->instanceof(Command::class)->tag('console.command');

    $services->load('Stewart\\Runtime\\Console\\', '../src/Console/');
    $services->load('Stewart\\Runtime\\Config\\', '../src/Config/');
    $services->load('Stewart\\Runtime\\Control\\Client\\', '../src/Control/Client/');
    $services->set(ControlClient::class)->arg('$controlSocketConnector', inline_service(DnsSocketConnector::class));
    $services->set(ReadinessCheck::class);

    $services->set(ClosestNameFinder::class);
    $services->set(EnvironmentVariables::class)->factory([EnvironmentVariables::class, 'fromGlobals']);
    $services->set(AppDiscovery::class);
    $services->set(AppCatalogResolver::class);
    $services->set(CpuCountDetector::class);
    $services->set(AppPlacementFactory::class);
    $services->set(AppPlacement::class)->factory([service(AppPlacementFactory::class), 'createPlacementForHost']);
    $services->set(LoggerFactory::class);
    $services->set(RevoltTimers::class);
    $services->alias(Deadlines::class, RevoltTimers::class);

    $services->set(ProfileCompiler::class);
    $services->set(BrokerKernel::class);

    $builder->addCompilerPass(new AddConsoleCommandPass());
};
