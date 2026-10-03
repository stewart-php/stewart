<?php

declare(strict_types=1);

use Stewart\Runtime\Tests\Fixtures\Container\ScalarConsumer;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->defaults()
        ->autowire()
        ->bind('$entity', 'light.from_defaults')
        ->set(ScalarConsumer::class)
        ->public();
};
