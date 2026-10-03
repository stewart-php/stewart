<?php

declare(strict_types=1);

use Stewart\Runtime\Tests\Fixtures\Container\NeedsScalar;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set(NeedsScalar::class)
        ->arg('$entity', 'light.from_services');
};
