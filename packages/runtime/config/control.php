<?php

declare(strict_types=1);

use Stewart\Contracts\Time\Clock;
use Stewart\Runtime\Control\Protocol\Codec\FrameCodec;
use Stewart\Runtime\Json\WireMapper;
use Stewart\Runtime\Time\SystemClock;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();

    $services->set(FrameCodec::class)->arg('$controlWireMapper', inline_service(WireMapper::class)->factory([FrameCodec::class, 'createControlWireMapper']));
    $services->set(SystemClock::class)->factory([SystemClock::class, 'inUtc']);
    $services->alias(Clock::class, SystemClock::class);
};
