<?php

declare(strict_types=1);

namespace Stewart\Testing\App;

use Amp\Future;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Stewart\Contracts\App;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\AppTemplate;
use Stewart\Contracts\Automation;
use Stewart\Contracts\Disposable;
use Stewart\Contracts\Exception\IdentifierException;
use Stewart\Contracts\Exposure\EntityExposure;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Mqtt\Mqtt;
use Stewart\Contracts\Registry\RegistryEditor;
use Stewart\Contracts\Schedule\Scheduler;
use Stewart\Contracts\Store\ReadableStore;
use Stewart\Contracts\Store\Store;
use Stewart\Contracts\Sun\SunCalendar;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Timers;
use Stewart\Store\KeyspacedStores;
use Stewart\Store\ReadOnlyStore;
use Stewart\Store\StorePrefix;
use Stewart\Store\Stores;
use Stewart\Store\StoreValueCodec;
use Stewart\Support\Text\ClosestNameFinder;
use Stewart\Support\Text\OptionNameMatcher;
use Stewart\Testing\Exception\HarnessError;
use Stewart\Testing\Exception\HarnessException;
use Stewart\Testing\Exposure\RecordingEntityExposure;
use Stewart\Testing\HaContext\RecordingHaContext;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Mqtt\RecordingMqtt;
use Stewart\Testing\Registry\RecordingRegistryEditor;
use Stewart\Testing\Schedule\ManualScheduler;
use Stewart\Testing\Store\InMemoryStoreBackend;
use Stewart\Testing\Sun\FixedSunCalendar;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;
use Stewart\Testing\Time\VirtualClock;

use function Amp\async;

final class AppHarness
{
    private const string STORE_PREFIX = 'stewart';

    public readonly ManualTimers $timers;

    public readonly VirtualClock $clock;

    public readonly RecordingHaContext $ha;

    public readonly RecordingEntityExposure $entities;

    public readonly ManualScheduler $scheduler;

    public readonly FixedSunCalendar $sunCalendar;

    public readonly Stores $stores;

    public readonly RecordingMqtt $mqtt;

    public readonly RecordingLogger $logger;

    public readonly RecordingRegistryEditor $registryEditor;

    /** @var array<string, object> */
    private array $providedServices = [];

    private ?App $app = null;

    /** @var Future<mixed>|null */
    private ?Future $initializing = null;

    public function __construct(?DateTimeImmutable $start = null)
    {
        $this->timers = new ManualTimers(new VirtualClock($start));
        $this->clock = $this->timers->clock;
        $this->ha = new RecordingHaContext($this->timers);
        $this->entities = new RecordingEntityExposure(timers: $this->timers);
        $this->sunCalendar = new FixedSunCalendar($this->clock);
        $this->scheduler = new ManualScheduler($this->timers, $this->sunCalendar, $this->ha);
        $this->stores = new KeyspacedStores(new InMemoryStoreBackend($this->clock), new StorePrefix(self::STORE_PREFIX), AppIdCollection::empty(), new StoreValueCodec(), new ClosestNameFinder());
        $this->mqtt = new RecordingMqtt($this->timers);
        $this->logger = new RecordingLogger();
        $this->registryEditor = new RecordingRegistryEditor($this->ha->registry);
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @param T $service
     * @throws HarnessException
     */
    public function provideService(string $type, object $service): void
    {
        $this->requireNotStarted();
        $this->providedServices[$type] = $service;
    }

    /**
     * @template T of App
     * @param class-string<T> $class
     * @param array<string, mixed> $options
     * @return T
     * @throws HarnessException
     * @throws IdentifierException
     */
    public function startApp(string $class, array $options = [], ?string $appId = null): App
    {
        $this->requireNotStarted();
        $reflection = new ReflectionClass($class);
        $appId = $this->resolveAppId($reflection, $appId);
        $this->entities->assignAppId($appId);
        $resolver = new AppArgumentResolver([...$this->createFakesFor($appId), ...$this->providedServices], $this->stores, $this->ha, new OptionNameMatcher(new ClosestNameFinder()));
        $app = $reflection->newInstanceArgs($resolver->resolveArguments($reflection, $options));
        $this->app = $app;

        // Like the worker, nothing reaches the app until initialize() returns.
        $this->timers->handlerFibers->holdDeliveries();
        $this->initializing = async($app->initialize(...));
        EventLoopTicks::settle();
        $this->finishInitializing();

        return $app;
    }

    /** @throws HarnessException */
    public function advanceTime(Duration $by): void
    {
        $this->timers->delay($by);
        $this->finishInitializing();
        $this->timers->handlerFibers->rethrowFailures();
    }

    /** @throws HarnessException */
    public function stopApp(): void
    {
        $app = $this->app ?? throw HarnessException::createForReason(HarnessError::NotStarted);

        $this->scheduler->cancelAll();
        $this->ha->cancelSubscriptions();
        $this->entities->cancelSubscriptions();
        $this->mqtt->cancelSubscriptions();
        EventLoopTicks::settle();
        $this->timers->handlerFibers->rethrowFailuresExceptAbandonedWaits();

        if ($app instanceof Disposable) {
            async($app->dispose(...))->await();
        }
    }

    /** @return array<string, object> */
    private function createFakesFor(AppId $appId): array
    {
        $store = $this->stores->openAppStore($appId);

        return [
            HaContext::class => $this->ha,
            EntityExposure::class => $this->entities,
            Scheduler::class => $this->scheduler,
            Timers::class => $this->timers,
            Clock::class => $this->clock,
            Store::class => $store,
            ReadableStore::class => new ReadOnlyStore($store),
            Mqtt::class => $this->mqtt,
            LoggerInterface::class => $this->logger,
            RegistryEditor::class => $this->registryEditor,
            SunCalendar::class => $this->sunCalendar,
        ];
    }

    private function finishInitializing(): void
    {
        if ($this->initializing === null || !$this->initializing->isComplete()) {
            return;
        }

        $initializing = $this->initializing;
        $this->initializing = null;
        $this->timers->handlerFibers->releaseDeliveries();
        $initializing->await();
    }

    /**
     * @template T of App
     * @param ReflectionClass<T> $reflection
     * @throws HarnessException
     * @throws IdentifierException
     */
    private function resolveAppId(ReflectionClass $reflection, ?string $appId): AppId
    {
        $automation = ($reflection->getAttributes(Automation::class)[0] ?? null)?->newInstance();
        $isTemplate = $reflection->getAttributes(AppTemplate::class) !== [];

        if ($automation === null && !$isTemplate) {
            throw HarnessException::createForReason(HarnessError::AutomationMissing, class: $reflection->getName());
        }

        if ($appId !== null) {
            return new AppId($appId);
        }

        return $automation === null
            ? throw HarnessException::createForReason(HarnessError::TemplateNeedsAppId, class: $reflection->getName())
            : new AppId($automation->id);
    }

    /** @throws HarnessException */
    private function requireNotStarted(): void
    {
        if ($this->app !== null) {
            throw HarnessException::createForReason(HarnessError::AlreadyStarted, class: $this->app::class);
        }
    }
}
