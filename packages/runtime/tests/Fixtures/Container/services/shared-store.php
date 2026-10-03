<?php

declare(strict_types=1);

use Stewart\Runtime\Tests\Fixtures\Container\SharedStoreConsumer;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->set(SharedStoreConsumer::class)
        ->public();
};
