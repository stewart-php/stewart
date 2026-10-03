<?php

declare(strict_types=1);

use Stewart\Contracts\Time\Timers;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Stewart\Runtime\Ipc\Wire\IpcMessageCatalog;
use Stewart\Runtime\Json\WireMapper;
use Stewart\Runtime\Time\DefaultProcessTimeZone;
use Stewart\Runtime\Time\ProcessTimeZone;
use Stewart\Store\StoreBackendConnector;
use Stewart\Store\StoreBackendRegistry;
use Stewart\Support\Text\ClosestNameFinder;
use Stewart\Support\Time\Deadlines;
use Stewart\Support\Time\RevoltTimers;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();

    $services->set(RevoltTimers::class);
    $services->alias(Timers::class, RevoltTimers::class);
    $services->alias(Deadlines::class, RevoltTimers::class);

    $services->set(ClosestNameFinder::class);

    $services->set(DefaultProcessTimeZone::class);
    $services->alias(ProcessTimeZone::class, DefaultProcessTimeZone::class)->public();

    $services->set(IpcMessageCatalog::class)->factory([IpcMessageCatalog::class, 'scanMessageDirectory']);
    $services->set(StoreBackendRegistry::class)->arg('$factories', tagged_iterator('stewart.store_backend'));
    $services->set(StoreBackendConnector::class);

    $services->set(IpcCodec::class)->arg('$ipcWireMapper', inline_service(WireMapper::class)->factory([IpcCodec::class, 'createIpcWireMapper']));
};
