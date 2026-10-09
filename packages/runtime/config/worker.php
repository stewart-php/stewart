<?php

declare(strict_types=1);

use Psr\Log\LoggerInterface;
use Stewart\Contracts\Sun\SunCalendar;
use Stewart\Runtime\App\GeneratedRoots;
use Stewart\Runtime\Dispatch\DispatchListener;
use Stewart\Runtime\Dispatch\SubscriptionListener;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Registry\RegistryCache;
use Stewart\Runtime\Schedule\AsyncHandlerRunner;
use Stewart\Runtime\Schedule\HandlerRunner;
use Stewart\Runtime\Schedule\ScheduleListener;
use Stewart\Runtime\Schedule\WorkerScheduler;
use Stewart\Runtime\State\StateCache;
use Stewart\Runtime\Worker\AppActivityRecorder;
use Stewart\Runtime\Worker\BrokerSubscriptions;
use Stewart\Runtime\Worker\DsnStoreBackendOpener;
use Stewart\Runtime\Worker\Message\BrokerMessageDispatcher;
use Stewart\Runtime\Worker\Message\BrokerMessageHandler;
use Stewart\Runtime\Worker\StoreBackendOpener;
use Stewart\Runtime\Worker\WorkerEntityExposure;
use Stewart\Runtime\Worker\WorkerHaContext;
use Stewart\Runtime\Worker\WorkerLogger;
use Stewart\Runtime\Worker\WorkerMqtt;
use Stewart\Runtime\Worker\WorkerRegistryEditor;
use Stewart\Runtime\Worker\WorkerSession;
use Stewart\Runtime\Worker\WorkerStoresFactory;
use Stewart\Runtime\Worker\WorkerSunCalendarFactory;
use Stewart\Store\GuardedStoreBackend;
use Stewart\Store\StoreBackend;
use Stewart\Store\Stores;
use Stewart\Store\StoreValueCodec;
use Stewart\Sun\NoaaSolarCalculator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();

    $services->instanceof(BrokerMessageHandler::class)->tag('stewart.broker_message_handler');

    $services->load('Stewart\\Runtime\\Worker\\', '../src/Worker/');
    $services->load('Stewart\\Runtime\\Dispatch\\', '../src/Dispatch/');
    $services->load('Stewart\\Runtime\\Schedule\\', '../src/Schedule/');
    $services->load('Stewart\\Runtime\\Container\\', '../src/Container/');
    $services->load('Stewart\\Runtime\\Scope\\', '../src/Scope/');

    $services->set(WorkerSession::class)->public();

    $services->set(WorkerLogger::class)->arg('$resourceScope', inline_service(ResourceScope::class)->factory([ResourceScope::class, 'shared']));
    $services->set(WorkerHaContext::class)->arg('$resourceScope', inline_service(ResourceScope::class)->factory([ResourceScope::class, 'shared']));
    $services->set(WorkerScheduler::class)->arg('$resourceScope', inline_service(ResourceScope::class)->factory([ResourceScope::class, 'shared']));
    $services->set(WorkerMqtt::class)->arg('$resourceScope', inline_service(ResourceScope::class)->factory([ResourceScope::class, 'shared']));
    $services->set(WorkerEntityExposure::class)->arg('$resourceScope', inline_service(ResourceScope::class)->factory([ResourceScope::class, 'shared']));
    $services->set(WorkerRegistryEditor::class)->arg('$resourceScope', inline_service(ResourceScope::class)->factory([ResourceScope::class, 'shared']));
    $services->set(BrokerMessageDispatcher::class)->arg('$handlers', tagged_iterator('stewart.broker_message_handler'));

    $services->alias(LoggerInterface::class, WorkerLogger::class);
    $services->alias(DispatchListener::class, AppActivityRecorder::class);
    $services->alias(ScheduleListener::class, AppActivityRecorder::class);
    $services->alias(SubscriptionListener::class, BrokerSubscriptions::class);
    $services->alias(HandlerRunner::class, AsyncHandlerRunner::class);

    $services->set(StateCache::class);
    $services->set(RegistryCache::class);
    $services->set(StoreValueCodec::class);
    $services->set(GeneratedRoots::class)->factory([GeneratedRoots::class, 'fromNamespace']);

    $services->alias(StoreBackendOpener::class, DsnStoreBackendOpener::class);
    $services->set(GuardedStoreBackend::class)->factory([service(StoreBackendOpener::class), 'openForStoreSettings']);
    $services->alias(StoreBackend::class, GuardedStoreBackend::class);
    $services->set(Stores::class)->factory([service(WorkerStoresFactory::class), 'createStores']);

    $services->set(NoaaSolarCalculator::class);
    $services->set(SunCalendar::class)->factory([service(WorkerSunCalendarFactory::class), 'createSunCalendar']);
};
