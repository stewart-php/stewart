<?php

declare(strict_types=1);

namespace Stewart\Runtime\Container;

use Closure;
use Psr\Log\LoggerInterface;
use Stewart\Contracts\App;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\ExceptionReason;
use Stewart\Contracts\Exception\MqttError;
use Stewart\Contracts\Exception\MqttException;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Contracts\Exception\StoreError;
use Stewart\Contracts\Exception\StoreException;
use Stewart\Contracts\Exposure\EntityExposure;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Mqtt\Mqtt;
use Stewart\Contracts\Registry\RegistryEditor;
use Stewart\Contracts\Schedule\Scheduler;
use Stewart\Contracts\Store\ReadableStore;
use Stewart\Contracts\Store\Store;
use Stewart\Contracts\Sun\SunCalendar;
use Stewart\Contracts\Time\Clock;
use Stewart\Runtime\App\GeneratedRoots;
use Stewart\Runtime\Container\Collection\AppBuildFailureCollection;
use Stewart\Runtime\Exception\ContainerError;
use Stewart\Runtime\Exception\ContainerException;
use Stewart\Runtime\Ipc\Collection\WorkerAppCollection;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Schedule\WorkerScheduler;
use Stewart\Runtime\Worker\WorkerEntityExposure;
use Stewart\Runtime\Worker\WorkerHaContext;
use Stewart\Runtime\Worker\WorkerLogger;
use Stewart\Runtime\Worker\WorkerMqtt;
use Stewart\Runtime\Worker\WorkerRegistryEditor;
use Stewart\Store\ReadOnlyStore;
use Stewart\Store\ScopedStore;
use Stewart\Store\Stores;
use Stewart\Support\Text\ClosestNameFinder;
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

    private const string LOGGER_SUFFIX = '.logger';

    private const string CONTEXT_SUFFIX = '.context';

    private const string SCHEDULER_SUFFIX = '.scheduler';

    private const string STORE_SUFFIX = '.store';

    private const string READ_ONLY_STORE_SUFFIX = '.store.read_only';

    private const string MQTT_SUFFIX = '.mqtt';

    private const string EXPOSURE_SUFFIX = '.exposure';

    private const string REGISTRY_EDITOR_SUFFIX = '.registry_editor';

    private const string ENTITIES_SUFFIX = '.entities';

    private const string ACTIONS_SUFFIX = '.actions';

    private const array ALWAYS_AVAILABLE_SERVICE_IDS = [
        Clock::class,
        SunCalendar::class,
        self::LOGGER,
        self::CONTEXT,
        self::SCHEDULER,
        self::EXPOSURE,
        self::REGISTRY_EDITOR,
    ];

    public function __construct(
        private AppOptionResolver $optionResolver,
        private ClosestNameFinder $closestNameFinder,
    ) {}

    public static function buildAppServiceId(AppId $appId): string
    {
        return '.stewart.app.' . $appId->value;
    }

    public static function buildPeerStoreServiceId(AppId $appId): string
    {
        return '.stewart.store.peer.' . $appId->value;
    }

    /** @throws StewartException */
    public function buildAppContainer(WorkerAppCollection $apps, AppContainerBlueprint $blueprint, AppRuntimeServices $runtime, WorkerId $workerId): AppContainerBuild
    {
        return $this->compileAppContainer($apps, $blueprint, $workerId, $runtime);
    }

    /** @throws StewartException */
    public function findAppBuildFailures(WorkerAppCollection $apps, AppContainerBlueprint $blueprint, WorkerId $workerId): AppBuildFailureCollection
    {
        return $this->compileAppContainer($apps, $blueprint, $workerId)->failures;
    }

    /** @throws StewartException */
    private function compileAppContainer(WorkerAppCollection $apps, AppContainerBlueprint $blueprint, WorkerId $workerId, ?AppRuntimeServices $runtime = null): AppContainerBuild
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

        try {
            $container = $this->compileContainerFor($registrations, $blueprint, $workerId, $runtime);
        } catch (Throwable $e) {
            if ($registrations === []) {
                throw $this->explainWorkerFailure($workerId, $e);
            }

            return $this->isolateUnbuildableApps($registrations, $failures, $blueprint, $workerId, $runtime);
        }

        return new AppContainerBuild($container, AppBuildFailureCollection::keyedByAppId($failures));
    }

    /**
     * @param list<AppRegistration> $registrations
     * @param list<AppBuildFailure> $failures
     * @throws StewartException
     */
    private function isolateUnbuildableApps(
        array $registrations,
        array $failures,
        AppContainerBlueprint $blueprint,
        WorkerId $workerId,
        ?AppRuntimeServices $runtime,
    ): AppContainerBuild {
        $this->compileOrFailWorker([], $blueprint, $workerId);
        $survivors = [];

        foreach ($registrations as $registration) {
            try {
                $this->compileContainerFor([$registration], $blueprint, $workerId);
                $survivors[] = $registration;
            } catch (Throwable $e) {
                $appId = $registration->app->id;
                $failures[] = new AppBuildFailure($appId, ContainerException::createForReason(ContainerError::AppBuildFailed, $e, appId: $appId->value));
            }
        }

        return new AppContainerBuild($this->compileOrFailWorker($survivors, $blueprint, $workerId, $runtime), AppBuildFailureCollection::keyedByAppId($failures));
    }

    /**
     * @param list<AppRegistration> $registrations
     * @throws StewartException
     */
    private function compileOrFailWorker(array $registrations, AppContainerBlueprint $blueprint, WorkerId $workerId, ?AppRuntimeServices $runtime = null): TypedContainer
    {
        try {
            return $this->compileContainerFor($registrations, $blueprint, $workerId, $runtime);
        } catch (Throwable $e) {
            throw $this->explainWorkerFailure($workerId, $e);
        }
    }

    /**
     * @param list<AppRegistration> $registrations
     * @throws Throwable
     */
    private function compileContainerFor(array $registrations, AppContainerBlueprint $blueprint, WorkerId $workerId, ?AppRuntimeServices $runtime = null): TypedContainer
    {
        $container = new ContainerBuilder();
        $container->setParameter('stewart.worker_id', $workerId->value);

        if ($blueprint->servicesFile !== null) {
            $this->loadUserServices($container, $blueprint->servicesFile);
        }

        $this->registerFrameworkServices($container, $blueprint);

        foreach ($registrations as $registration) {
            $this->registerApp($container, $registration, $blueprint->generated);
        }

        $container->addCompilerPass(new StoreAttributesPass($blueprint->knownAppIds, $this->closestNameFinder), PassConfig::TYPE_BEFORE_OPTIMIZATION);
        $container->compile();

        if ($runtime !== null) {
            $this->attachRuntimeServices($container, $blueprint, $runtime);
        }

        return new TypedContainer($container);
    }

    /** @return StewartException<ExceptionReason> */
    private function explainAppFailure(AppId $appId, Throwable $error): StewartException
    {
        return $error instanceof StewartException ? $error : ContainerException::createForReason(ContainerError::AppBuildFailed, $error, appId: $appId->value);
    }

    /** @return StewartException<ExceptionReason> */
    private function explainWorkerFailure(WorkerId $workerId, Throwable $error): StewartException
    {
        return $error instanceof StewartException ? $error : ContainerException::createForReason(
            ContainerError::BuildFailed,
            $error,
            workerId: $workerId->value,
        );
    }

    /** @throws ContainerException */
    private function loadUserServices(ContainerBuilder $container, string $servicesFile): void
    {
        if (!is_file($servicesFile)) {
            throw ContainerException::createForReason(ContainerError::ServicesFileMissing, path: $servicesFile);
        }

        new PhpFileLoader($container, new FileLocator(\dirname($servicesFile)))->load(basename($servicesFile));
        $this->rejectAppDefinitions($container);
    }

    private function registerFrameworkServices(ContainerBuilder $container, AppContainerBlueprint $blueprint): void
    {
        foreach (self::ALWAYS_AVAILABLE_SERVICE_IDS as $id) {
            $this->registerRuntimeService($container, $id);
        }

        $this->registerConfigurableRuntimeService(
            $container,
            self::STORES,
            Stores::class,
            $blueprint->storeConfigured,
            static fn(): string => StoreException::createForReason(StoreError::NotConfigured)->getMessage(),
        );
        $this->registerConfigurableRuntimeService(
            $container,
            self::MQTT,
            WorkerMqtt::class,
            $blueprint->mqttConfigured,
            static fn(): string => MqttException::createForReason(MqttError::NotConfigured)->getMessage(),
        );

        // A user service belongs to no automation, so its Store is the global scope.
        $container->register(self::GLOBAL_STORE, ScopedStore::class)
            ->setFactory([new Reference(self::STORES), 'openGlobalStore']);
        $container->register(self::READ_ONLY_GLOBAL_STORE, ReadOnlyStore::class)
            ->setArguments([new Reference(self::GLOBAL_STORE)]);

        $container->setAlias(LoggerInterface::class, self::LOGGER)->setPublic(true);
        $container->setAlias(HaContext::class, self::CONTEXT)->setPublic(true);
        $container->setAlias(Scheduler::class, self::SCHEDULER)->setPublic(true);
        // Private, so an unconfigured store or MQTT fails only the services that take them.
        $container->setAlias(Store::class, self::GLOBAL_STORE);
        $container->setAlias(ReadableStore::class, self::READ_ONLY_GLOBAL_STORE);
        $container->setAlias(Mqtt::class, self::MQTT);
        $container->setAlias(EntityExposure::class, self::EXPOSURE)->setPublic(true);
        $container->setAlias(RegistryEditor::class, self::REGISTRY_EDITOR)->setPublic(true);

        $this->registerSharedGeneratedRoots($container, $blueprint->generated);
    }

    private function registerRuntimeService(ContainerBuilder $container, string $id): void
    {
        // Public so unused definitions survive compilation and can still be set.
        $container->register($id)->setSynthetic(true)->setPublic(true);
    }

    /** @param Closure(): string $describeMissingConfiguration */
    private function registerConfigurableRuntimeService(ContainerBuilder $container, string $id, string $class, bool $configured, Closure $describeMissingConfiguration): void
    {
        if ($configured) {
            $this->registerRuntimeService($container, $id);

            return;
        }

        // Compiling drops unused definitions before it reports errors, so only services that reach this one fail.
        $container->register($id, $class)->addError($describeMissingConfiguration);
    }

    private function attachRuntimeServices(ContainerBuilder $container, AppContainerBlueprint $blueprint, AppRuntimeServices $runtime): void
    {
        $services = [
            Clock::class => $runtime->clock,
            SunCalendar::class => $runtime->sunCalendar,
            self::LOGGER => $runtime->logger,
            self::CONTEXT => $runtime->context,
            self::SCHEDULER => $runtime->scheduler,
            self::EXPOSURE => $runtime->exposure,
            self::REGISTRY_EDITOR => $runtime->registryEditor,
        ];

        if ($blueprint->storeConfigured) {
            $services[self::STORES] = $runtime->stores;
        }

        if ($blueprint->mqttConfigured) {
            $services[self::MQTT] = $runtime->mqtt;
        }

        foreach ($services as $id => $service) {
            $container->set($id, $service);
        }
    }

    /** @throws ContainerException */
    private function rejectAppDefinitions(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass() ?? $id;

            if (is_a($class, App::class, true)) {
                throw ContainerException::createForReason(ContainerError::AppInServicesFile, serviceId: $id, class: $class);
            }
        }
    }

    private function registerSharedGeneratedRoots(ContainerBuilder $container, ?GeneratedRoots $generated): void
    {
        if ($generated === null) {
            return;
        }

        foreach ([$generated->entitiesClass, $generated->actionsClass] as $class) {
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

        foreach ([self::ENTITIES_SUFFIX => $generated->entitiesClass, self::ACTIONS_SUFFIX => $generated->actionsClass] as $suffix => $class) {
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
            Store::class => $serviceId . self::STORE_SUFFIX,
            ReadableStore::class => $serviceId . self::READ_ONLY_STORE_SUFFIX,
            Mqtt::class => $serviceId . self::MQTT_SUFFIX,
            EntityExposure::class => $serviceId . self::EXPOSURE_SUFFIX,
            RegistryEditor::class => $serviceId . self::REGISTRY_EDITOR_SUFFIX,
        ];

        if ($generated !== null) {
            $serviceIdsByType[$generated->entitiesClass] = $serviceId . self::ENTITIES_SUFFIX;
            $serviceIdsByType[$generated->actionsClass] = $serviceId . self::ACTIONS_SUFFIX;
        }

        return array_map(static fn(string $id): BoundArgument => new BoundArgument(new Reference($id), false), $serviceIdsByType);
    }
}
