<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// Register your own services here; apps receive them by type in their constructor.
return static function (ContainerConfigurator $container): void {
    $container->services()->defaults()->autowire();
};
