<?php

declare(strict_types=1);

namespace Stewart\Runtime\Container;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\App;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\ExceptionReason;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Contracts\Exposure\EntityExposure;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Identity\StewartIdentity;
use Stewart\Contracts\Mqtt\Mqtt;
use Stewart\Contracts\Registry\RegistryEditor;
use Stewart\Contracts\Schedule\Scheduler;
use Stewart\Contracts\Store\ReadableStore;
use Stewart\Contracts\Store\Store;
use Stewart\Contracts\Sun\SunCalendar;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Timers;
use Stewart\Runtime\App\GeneratedRoots;
use Stewart\Runtime\Container\Collection\AppBuildFailureCollection;
use Stewart\Runtime\Container\Collection\AppRegistrationCollection;
use Stewart\Runtime\Exception\ContainerException;
use Stewart\Runtime\Ipc\Collection\WorkerAppCollection;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Schedule\WorkerScheduler;
use Stewart\Runtime\Schedule\WorkerTimers;
use Stewart\Runtime\Worker\WorkerEntityExposure;
use Stewart\Runtime\Worker\WorkerHaContext;
use Stewart\Runtime\Worker\WorkerLogger;
use Stewart\Runtime\Worker\WorkerMqtt;
use Stewart\Runtime\Worker\WorkerRegistryEditor;
use Stewart\Store\ReadOnlyStore;
use Stewart\Store\ScopedStore;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\BoundArgument;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Throwable;

final readonly class AppContainerBuilder
{
    // A leading dot hides an id from autowiring, so user wiring sees only the contract types.
    public const string LOGGER = '.stewart.logger';

    public const string CONTEXT = '.stewart.ha';

    public const string SCHEDULER = '.stewart.scheduler';

    public const string STORES = '.stewart.stores';

    public const string MQTT = '.stewart.mqtt';

    public const string EXPOSURE = '.stewart.exposure';

    public const string REGISTRY_EDITOR = '.stewart.registry_editor';

    public const string GLOBAL_STORE = '.stewart.store.global';

    public const string READ_ONLY_GLOBAL_STORE = '.stewart.store.global.read_only';

    private const string TIMERS = '.stewart.timers';

    private const string LOGGER_SUFFIX = '.logger';

    private const string CONTEXT_SUFFIX = '.context';

    private const string SCHEDULER_SUFFIX = '.scheduler';

    private const string TIMERS_SUFFIX = '.timers';

    private const string STORE_SUFFIX = '.store';

    private const string READ_ONLY_STORE_SUFFIX = '.store.read_only';

    private const string MQTT_SUFFIX = '.mqtt';

    private const string EXPOSURE_SUFFIX = '.exposure';

    private const string REGISTRY_EDITOR_SUFFIX = '.registry_editor';

    private const string ENTITIES_SUFFIX = '.entities';

    private const string SERVICES_SUFFIX = '.services';

    public function __construct(private AppOptionResolver $optionResolver) {}

    public static function buildAppServiceId(AppId $appId): string
    {
        return '.stewart.app.' . $appId->value;
    }

    public static function buildPeerStoreServiceId(AppId $appId): string
    {
        return '.stewart.store.peer.' . $appId->value;
    }

    /** @throws StewartException */
    public function buildAppContainer(WorkerAppCollection $apps, AppRuntimeServices $runtime, ?string $servicesFile, WorkerId $workerId): AppContainerBuild
    {
        $registrations = [];
        $failures = [];

        foreach ($apps as $app) {
            try {
                $registrations[] = new AppRegistration($app, $this->optionResolver->resolveOptionArguments($app));
            } catch (Throwable $e) {
                $failures[] = new AppBuildFailure($app->id, $this->explainAppFailure($app->id, $e));
            }
        }

        $registered = AppRegistrationCollection::fromRegistrations($registrations);

        try {
            $container = $this->compileContainerFor($registered, $runtime, $servicesFile, $workerId);
        } catch (Throwable $e) {
            if ($registered->isEmpty()) {
                throw $this->explainWorkerFailure($workerId, $e);
            }

            return $this->isolateUnbuildableApps($registered, $failures, $runtime, $servicesFile, $workerId);
        }

        return new AppContainerBuild($container, AppBuildFailureCollection::keyedByAppId($failures));
    }

    /**
     * @param list<AppBuildFailure> $failures
     * @throws StewartException
     */
    private function isolateUnbuildableApps(
        AppRegistrationCollection $registrations,
        array $failures,
        AppRuntimeServices $runtime,
        ?string $servicesFile,
        WorkerId $workerId,
    ): AppContainerBuild {
        $this->compileOrFailWorker(AppRegistrationCollection::empty(), $runtime, $servicesFile, $workerId);
        $isolated = [];

        foreach ($registrations as $registration) {
            try {
                $this->compileContainerFor(AppRegistrationCollection::fromRegistrations([$registration]), $runtime, $servicesFile, $workerId);
            } catch (Throwable $e) {
                $failures[] = new AppBuildFailure($registration->app->id, $this->explainAppFailure($registration->app->id, $e));
                $isolated[] = $registration->app->id->value;
            }
        }

        if ($isolated !== []) {
            $runtime->logger->warning('The app container failed to compile; apps that cannot be built were left out', ['apps' => $isolated]);
        }

        $survivors = $registrations->filter(static fn(AppRegistration $registration): bool => !\in_array($registration->app->id->value, $isolated, true));

        return new AppContainerBuild($this->compileOrFailWorker($survivors, $runtime, $servicesFile, $workerId), AppBuildFailureCollection::keyedByAppId($failures));
    }

    /** @throws StewartException */
    private function compileOrFailWorker(AppRegistrationCollection $registrations, AppRuntimeServices $runtime, ?string $servicesFile, WorkerId $workerId): TypedContainer
    {
        try {
            return $this->compileContainerFor($registrations, $runtime, $servicesFile, $workerId);
        } catch (Throwable $e) {
            throw $this->explainWorkerFailure($workerId, $e);
        }
    }

    /** @throws Throwable */
    private function compileContainerFor(AppRegistrationCollection $registrations, AppRuntimeServices $runtime, ?string $servicesFile, WorkerId $workerId): TypedContainer
    {
        $container = new ContainerBuilder();
        $container->setParameter('stewart.worker_id', $workerId->value);

        if ($servicesFile !== null) {
            $this->loadUserServices($container, $servicesFile);
        }

        $synthetics = [
            Clock::class => $runtime->clock,
            StewartIdentity::class => $runtime->identity,
            SunCalendar::class => $runtime->sunCalendar,
            self::LOGGER => $runtime->logger,
            self::CONTEXT => $runtime->context,
            self::SCHEDULER => $runtime->scheduler,
            self::STORES => $runtime->stores,
            self::MQTT => $runtime->mqtt,
            self::EXPOSURE => $runtime->exposure,
            self::REGISTRY_EDITOR => $runtime->registryEditor,
        ];

        $this->registerFrameworkServices($container, array_keys($synthetics), $runtime->generated);

        foreach ($registrations as $registration) {
            $this->registerApp($container, $registration, $runtime->generated);
        }

        return $this->compileWithSynthetics($container, $synthetics);
    }

    /** @return StewartException<ExceptionReason> */
    private function explainAppFailure(AppId $appId, Throwable $error): StewartException
    {
        return $error instanceof StewartException ? $error : ContainerException::appBuildFailed($appId, $error);
    }

    /** @return StewartException<ExceptionReason> */
    private function explainWorkerFailure(WorkerId $workerId, Throwable $error): StewartException
    {
        return $error instanceof StewartException ? $error : ContainerException::buildFailed($workerId, $error);
    }

    /** @throws ContainerException */
    private function loadUserServices(ContainerBuilder $container, string $servicesFile): void
    {
        if (!is_file($servicesFile)) {
            throw ContainerException::servicesFileMissing($servicesFile);
        }

        new PhpFileLoader($container, new FileLocator(\dirname($servicesFile)))->load(basename($servicesFile));
        $this->rejectAppDefinitions($container);
    }

    /** @param list<string> $syntheticIds */
    private function registerFrameworkServices(ContainerBuilder $container, array $syntheticIds, ?GeneratedRoots $generated): void
    {
        foreach ($syntheticIds as $id) {
            // Public so unused definitions survive compilation and can still be set.
            $container->register($id)->setSynthetic(true)->setPublic(true);
        }

        // A user service belongs to no automation, so its Store is the global scope.
        $container->register(self::GLOBAL_STORE, ScopedStore::class)
            ->setFactory([new Reference(self::STORES), 'openGlobalStore']);
        $container->register(self::READ_ONLY_GLOBAL_STORE, ReadOnlyStore::class)
            ->setArguments([new Reference(self::GLOBAL_STORE)]);
        $container->register(self::TIMERS, WorkerTimers::class)
            ->setArguments([new Reference(self::SCHEDULER)]);

        $container->setAlias(LoggerInterface::class, self::LOGGER)->setPublic(true);
        $container->setAlias(HaContext::class, self::CONTEXT)->setPublic(true);
        $container->setAlias(Scheduler::class, self::SCHEDULER)->setPublic(true);
        $container->setAlias(Timers::class, self::TIMERS)->setPublic(true);
        $container->setAlias(Store::class, self::GLOBAL_STORE)->setPublic(true);
        $container->setAlias(ReadableStore::class, self::READ_ONLY_GLOBAL_STORE)->setPublic(true);
        $container->setAlias(Mqtt::class, self::MQTT)->setPublic(true);
        $container->setAlias(EntityExposure::class, self::EXPOSURE)->setPublic(true);
        $container->setAlias(RegistryEditor::class, self::REGISTRY_EDITOR)->setPublic(true);

        $this->registerSharedGeneratedRoots($container, $generated);
    }

    /** @param array<string, object> $synthetics */
    private function compileWithSynthetics(ContainerBuilder $container, array $synthetics): TypedContainer
    {
        $container->addCompilerPass(new StoreAttributesPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION);
        $container->compile();

        foreach ($synthetics as $id => $service) {
            $container->set($id, $service);
        }

        return new TypedContainer($container);
    }

    /** @throws ContainerException */
    private function rejectAppDefinitions(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass() ?? $id;

            if (is_a($class, App::class, true)) {
                throw ContainerException::appInServicesFile($id, $class);
            }
        }
    }

    private function registerSharedGeneratedRoots(ContainerBuilder $container, ?GeneratedRoots $generated): void
    {
        if ($generated === null) {
            return;
        }

        foreach ([$generated->entitiesClass, $generated->servicesClass] as $class) {
            $container->register($class, $class)
                ->setArguments([new Reference(self::CONTEXT)])
                ->setPublic(true);
        }
    }

    private function registerApp(ContainerBuilder $container, AppRegistration $registration, ?GeneratedRoots $generated): void
    {
        $appId = $registration->app->id;
        $serviceId = self::buildAppServiceId($appId);
        $this->registerAppScopedServices($container, $appId, $serviceId, $generated);

        $container->register($serviceId, $registration->app->class)
            ->setPublic(true)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setBindings($this->buildAppBindings($serviceId, $generated))
            ->setArguments($registration->optionArguments);
    }

    private function registerAppScopedServices(ContainerBuilder $container, AppId $appId, string $serviceId, ?GeneratedRoots $generated): void
    {
        $container->register($serviceId . self::LOGGER_SUFFIX, WorkerLogger::class)
            ->setFactory([new Reference(self::LOGGER), 'forApp'])
            ->addArgument($appId);

        $container->register($serviceId . self::CONTEXT_SUFFIX, WorkerHaContext::class)
            ->setFactory([new Reference(self::CONTEXT), 'forApp'])
            ->addArgument($appId);

        $container->register($serviceId . self::SCHEDULER_SUFFIX, WorkerScheduler::class)
            ->setFactory([new Reference(self::SCHEDULER), 'forApp'])
            ->setArguments([$appId, new Reference($serviceId . self::LOGGER_SUFFIX)]);

        $container->register($serviceId . self::TIMERS_SUFFIX, WorkerTimers::class)
            ->setArguments([new Reference($serviceId . self::SCHEDULER_SUFFIX)]);

        $container->register($serviceId . self::STORE_SUFFIX, ScopedStore::class)
            ->setFactory([new Reference(self::STORES), 'openAppStore'])
            ->addArgument($appId);

        $container->register($serviceId . self::READ_ONLY_STORE_SUFFIX, ReadOnlyStore::class)
            ->addArgument(new Reference($serviceId . self::STORE_SUFFIX));

        $container->register($serviceId . self::MQTT_SUFFIX, WorkerMqtt::class)
            ->setFactory([new Reference(self::MQTT), 'forApp'])
            ->addArgument($appId);

        $container->register($serviceId . self::EXPOSURE_SUFFIX, WorkerEntityExposure::class)
            ->setFactory([new Reference(self::EXPOSURE), 'forApp'])
            ->addArgument($appId);

        $container->register($serviceId . self::REGISTRY_EDITOR_SUFFIX, WorkerRegistryEditor::class)
            ->setFactory([new Reference(self::REGISTRY_EDITOR), 'forApp'])
            ->addArgument($appId);

        if ($generated === null) {
            return;
        }

        foreach ([self::ENTITIES_SUFFIX => $generated->entitiesClass, self::SERVICES_SUFFIX => $generated->servicesClass] as $suffix => $class) {
            $container->register($serviceId . $suffix, $class)->setArguments([new Reference($serviceId . self::CONTEXT_SUFFIX)]);
        }
    }

    // Untracked, like the framework bindings, so an app that uses none of them still compiles.
    /** @return array<string, BoundArgument> */
    private function buildAppBindings(string $serviceId, ?GeneratedRoots $generated): array
    {
        $serviceIdsByType = [
            HaContext::class => $serviceId . self::CONTEXT_SUFFIX,
            LoggerInterface::class => $serviceId . self::LOGGER_SUFFIX,
            Scheduler::class => $serviceId . self::SCHEDULER_SUFFIX,
            Timers::class => $serviceId . self::TIMERS_SUFFIX,
            Store::class => $serviceId . self::STORE_SUFFIX,
            ReadableStore::class => $serviceId . self::READ_ONLY_STORE_SUFFIX,
            Mqtt::class => $serviceId . self::MQTT_SUFFIX,
            EntityExposure::class => $serviceId . self::EXPOSURE_SUFFIX,
            RegistryEditor::class => $serviceId . self::REGISTRY_EDITOR_SUFFIX,
        ];

        if ($generated !== null) {
            $serviceIdsByType[$generated->entitiesClass] = $serviceId . self::ENTITIES_SUFFIX;
            $serviceIdsByType[$generated->servicesClass] = $serviceId . self::SERVICES_SUFFIX;
        }

        return array_map(static fn(string $id): BoundArgument => new BoundArgument(new Reference($id), false), $serviceIdsByType);
    }
}
