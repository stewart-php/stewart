<?php

declare(strict_types=1);

use Amp\Parallel\Context\ProcessContextFactory;
use Amp\Parallel\Ipc\LocalIpcHub;
use Stewart\Client\HaClient;
use Stewart\Runtime\Broker\AppPauseOverrideCodec;
use Stewart\Runtime\Broker\AppPlacement;
use Stewart\Runtime\Broker\AppPlacementFactory;
use Stewart\Runtime\Broker\BrokerLifecycle;
use Stewart\Runtime\Broker\BrokerStoreBackendOpener;
use Stewart\Runtime\Broker\BrokerSubscriptionListener;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\ControlPlane;
use Stewart\Runtime\Broker\EventRouter;
use Stewart\Runtime\Broker\HaSession;
use Stewart\Runtime\Broker\Http\Collection\HttpListenerCollection;
use Stewart\Runtime\Broker\ManifestCheck;
use Stewart\Runtime\Broker\Message\WorkerMessageDispatcher;
use Stewart\Runtime\Broker\Message\WorkerMessageHandler;
use Stewart\Runtime\Broker\Mqtt\MqttLink;
use Stewart\Runtime\Broker\Mqtt\MqttLinkResolver;
use Stewart\Runtime\Broker\Mqtt\MqttMessageRouter;
use Stewart\Runtime\Broker\ProcessWorkerSpawner;
use Stewart\Runtime\Broker\SubscriptionRegistry;
use Stewart\Runtime\Broker\WebsocketHaSession;
use Stewart\Runtime\Broker\WorkerSpawner;
use Stewart\Runtime\Control\ControlPlaneFactory;
use Stewart\Runtime\Control\Request\ControlRequestDispatcher;
use Stewart\Runtime\Control\Request\ControlRequestHandler;
use Stewart\Runtime\Health\ProbeReportCodec;
use Stewart\Runtime\Health\ProbeReporter;
use Stewart\Runtime\Health\SnapshotProbeReporter;
use Stewart\Runtime\Http\Admin\AdminApiCodec;
use Stewart\Runtime\Http\HttpListenerFactory;
use Stewart\Runtime\Json\WireMapper;
use Stewart\Runtime\Metrics\RuntimeMetricsCollector;
use Stewart\Runtime\Metrics\RuntimeMetricSource;
use Stewart\Runtime\Registry\RegistryCache;
use Stewart\Runtime\State\StateCache;
use Stewart\Store\GuardedStoreBackend;
use Stewart\Store\StoreBackend;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();

    $services->instanceof(WorkerMessageHandler::class)->tag('stewart.worker_message_handler');
    $services->instanceof(ControlRequestHandler::class)->tag('stewart.control_request_handler');
    $services->instanceof(BrokerSubscriptionListener::class)->tag('stewart.broker_subscription_listener');
    $services->instanceof(RuntimeMetricSource::class)->tag('stewart.runtime_metric_source');

    $services->load('Stewart\\Runtime\\Broker\\', '../src/Broker/');
    $services->set(StateCache::class);
    $services->set(RegistryCache::class);
    $services->load('Stewart\\Runtime\\Control\\', '../src/Control/')->exclude('../src/Control/{Client,Protocol}');

    $services->set(BrokerLifecycle::class)->public();

    $services->set(HaClient::class)->factory([HaClient::class, 'fromConnectionConfig']);
    $services->alias(HaSession::class, WebsocketHaSession::class);

    $services->set(ProcessContextFactory::class)->autowire(false)->arg('$ipcHub', inline_service(LocalIpcHub::class));
    $services->alias(WorkerSpawner::class, ProcessWorkerSpawner::class);

    $services->set(AppPlacement::class)->factory([service(AppPlacementFactory::class), 'createPlacementForHost']);
    $services->set(WorkerSlotCollection::class)->factory([service(AppPlacement::class), 'planWorkerSlots']);
    $services->set(ManifestCheck::class)->factory([ManifestCheck::class, 'forGeneratedCode']);
    $services->set(WorkerMessageDispatcher::class)->arg('$handlers', tagged_iterator('stewart.worker_message_handler'));
    $services->set(ControlRequestDispatcher::class)->arg('$handlers', tagged_iterator('stewart.control_request_handler'));

    $services->set(GuardedStoreBackend::class)->factory([service(BrokerStoreBackendOpener::class), 'openConfiguredBackend']);
    $services->alias(StoreBackend::class, GuardedStoreBackend::class);
    $services->set(AppPauseOverrideCodec::class)->arg('$appPauseOverrideWireMapper', inline_service(WireMapper::class)->factory([AppPauseOverrideCodec::class, 'createOverrideWireMapper']));

    $services->set(SubscriptionRegistry::class)->arg('$brokerSubscriptionListeners', tagged_iterator('stewart.broker_subscription_listener'));
    $services->set(MqttLink::class)->factory([service(MqttLinkResolver::class), 'resolveMqttLink']);
    $services->alias(MqttMessageRouter::class, EventRouter::class);

    $services->set(Filesystem::class);
    $services->set(ControlPlane::class)->factory([service(ControlPlaneFactory::class), 'createControlPlane']);

    $services->load('Stewart\\Runtime\\Health\\', '../src/Health/');
    $services->load('Stewart\\Runtime\\Http\\', '../src/Http/')->exclude('../src/Http/Admin/Response');
    $services->load('Stewart\\Runtime\\Metrics\\', '../src/Metrics/')->exclude('../src/Metrics/Exposition');
    $services->set(RuntimeMetricsCollector::class)->arg('$runtimeMetricSources', tagged_iterator('stewart.runtime_metric_source'));
    $services->set(HttpListenerCollection::class)->factory([service(HttpListenerFactory::class), 'createHttpListeners']);
    $services->alias(ProbeReporter::class, SnapshotProbeReporter::class);
    $services->set(ProbeReportCodec::class)->arg('$probeReportWireMapper', inline_service(WireMapper::class)->factory([ProbeReportCodec::class, 'createProbeReportWireMapper']));
    $services->set(AdminApiCodec::class)->arg('$adminApiWireMapper', inline_service(WireMapper::class)->factory([AdminApiCodec::class, 'createAdminApiWireMapper']));
};
