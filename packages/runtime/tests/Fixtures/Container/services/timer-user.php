<?php

declare(strict_types=1);

use Stewart\Runtime\Tests\Fixtures\Container\TimerConsumer;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->defaults()
        ->autowire()
        ->set(TimerConsumer::class)
        ->public();
};
