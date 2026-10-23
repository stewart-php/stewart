<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker\Reload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\AppOrigin;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\DaemonLaunchOptions;
use Stewart\Runtime\Broker\GeneratedManifest;
use Stewart\Runtime\Broker\Reload\ConfigChangeClassifier;
use Stewart\Runtime\Broker\Reload\ReloadApplier;
use Stewart\Runtime\Broker\Reload\ReloadCheckApp;
use Stewart\Runtime\Broker\Reload\ReloadCheckReport;
use Stewart\Runtime\Broker\Reload\ReloadCheckReportCodec;
use Stewart\Runtime\Broker\Reload\ReloadCoordinator;
use Stewart\Runtime\Broker\Reload\ReloadPlanner;
use Stewart\Runtime\Broker\Reload\ReloadRequestOutcome;
use Stewart\Runtime\Broker\Reload\ReloadResult;
use Stewart\Runtime\Broker\Reload\ReloadState;
use Stewart\Runtime\Broker\Reload\ReloadTrigger;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Lifecycle\ReloadOutcome;
use Stewart\Runtime\Model\LogLevel;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Broker\AppTopologyFixture;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeWorkerSpawner;
use Stewart\Runtime\Tests\Fixtures\Broker\RecordingPoolListener;
use Stewart\Runtime\Tests\Fixtures\Broker\ReloadCoordinatorFixture;
use Stewart\Runtime\Tests\Fixtures\Broker\WorkerPoolFixture;
use Stewart\Runtime\Tests\Fixtures\Deploy\ScriptedCommandRunner;
use Stewart\Runtime\Tests\Fixtures\Templates\RoomLight;
use Stewart\Support\Wire\GeneratedFormat;
use Stewart\Testing\Async\Latch;
use Stewart\Testing\Filesystem\TempDirectory;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;

#[CoversClass(ReloadCoordinator::class)]
#[CoversClass(ReloadPlanner::class)]
#[CoversClass(ReloadApplier::class)]
#[CoversClass(ConfigChangeClassifier::class)]
#[CoversClass(ReloadState::class)]
#[CoversClass(ReloadResult::class)]
#[CoversClass(ReloadRequestOutcome::class)]
final class ReloadCoordinatorTest extends TestCase
{
    private const string NO_CONFIG_FILE = 'missing';

    private FakeWorkerSpawner $spawner;

    private WorkerPoolFixture $pools;

    private ScriptedCommandRunner $commands;

    private ReloadState $state;

    private RecordingLogger $logger;

    private RecordingPoolListener $listener;

    private ReloadCoordinatorFixture $reloads;

    private ReloadCheckReportCodec $reports;

    private TempDirectory $project;

    protected function setUp(): void
    {
        $this->spawner = new FakeWorkerSpawner();
        $this->logger = new RecordingLogger();
        $this->pools = WorkerPoolFixture::createWorkerPool($this->spawner, timers: new ManualTimers(), logger: $this->logger);
        $this->commands = new ScriptedCommandRunner();
        $this->state = new ReloadState();
        $this->listener = new RecordingPoolListener();
        $this->reports = new ReloadCheckReportCodec(ReloadCheckReportCodec::createReportWireMapper());
        $this->project = TempDirectory::createWithPrefix('stewart-reload-');
        $this->reloads = $this->createReloads();
        $this->pools->pool->startWorker(self::createSlot(0, 'demo'), $this->listener);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testReloadIsRefusedBeforeTheAppsRun(): void
    {
        self::assertSame(ReloadRequestOutcome::NotAccepting, $this->reloads->coordinator->requestReload(ReloadTrigger::Control));
        self::assertSame([], $this->commands->commands);
    }

    public function testReloadReplacesTheWorkersWithoutCountingRestarts(): void
    {
        $this->queueReport(['demo']);

        $last = $this->reload();

        self::assertSame(ReloadOutcome::Applied, $last->outcome);
        self::assertSame(2, $this->spawner->countSpawnedProcesses());
        self::assertSame(0, $this->pools->slots->findSlotState(new WorkerId(0))?->restarts);
    }

    public function testReloadStartsAnAddedApp(): void
    {
        $this->queueReport(['demo', 'porch']);

        $this->reload();

        self::assertSame(['demo', 'porch'], $this->reloads->topology->getCatalog()->enabled->listAppIds()->toStrings());
        self::assertSame(['demo', 'porch'], $this->listRunningAppIds());
    }

    public function testReloadStartsAConfiguredInstanceWithItsOptions(): void
    {
        $config = $this->project->getFilePath('stewart.yaml');
        file_put_contents($config, "apps:\n  hall-light:\n    class: Stewart\\Runtime\\Tests\\Fixtures\\Templates\\RoomLight\n    options:\n      light: light.hall\n");
        $this->reloads = $this->createReloads(new DaemonLaunchOptions($config));
        $apps = [new ReloadCheckApp('demo', Demo::class), new ReloadCheckApp('hall-light', RoomLight::class, AppOrigin::Configured)];
        $this->commands->queueResult(ReloadCoordinatorFixture::CONSOLE_SCRIPT, 0, $this->reports->encodeReport(new ReloadCheckReport($apps, [], [], (string) hash_file('xxh128', $config), templateClasses: [RoomLight::class])));

        $this->reload();

        self::assertSame(['light' => 'light.hall'], $this->reloads->topology->getCatalog()->enabled->find(new AppId('hall-light'))?->options);
        self::assertSame(['demo', 'hall-light'], $this->listRunningAppIds());
        self::assertSame([], $this->logger->listMessagesAt('notice'));
    }

    public function testReloadRetiresAWorkerLeftWithoutApps(): void
    {
        $this->queueReport([]);

        $this->reload();

        self::assertTrue($this->reloads->topology->getWorkerSlots()->isEmpty());
        self::assertNull($this->pools->slots->findSlotState(new WorkerId(0)));
        self::assertSame(['0: reload'], $this->listener->gone);
    }

    public function testFailedCheckLeavesEverythingAlone(): void
    {
        $this->commands->queueResult(ReloadCoordinatorFixture::CONSOLE_SCRIPT, 1, $this->reports->encodeReport(ReloadCheckReport::failed(['apps/Porch.php failed to load: syntax error'], self::NO_CONFIG_FILE)));

        $last = $this->reload();

        self::assertSame(ReloadOutcome::Failed, $last->outcome);
        self::assertSame('apps/Porch.php failed to load: syntax error', $last->error);
        self::assertSame(1, $this->spawner->countSpawnedProcesses());
        self::assertSame(['demo'], $this->reloads->topology->getCatalog()->knownIds->toStrings());
        self::assertSame(['Reload failed; the workers keep running as they were'], $this->logger->listMessagesAt('error'));
    }

    public function testConfigChangedAfterTheCheckIsNotApplied(): void
    {
        $this->queueReport(['demo', 'porch'], 'another-version');

        $last = $this->reload();

        self::assertSame(ReloadOutcome::Failed, $last->outcome);
        self::assertStringContainsString('changed while the reload checked it', (string) $last->error);
        self::assertSame(['demo'], $this->reloads->topology->getCatalog()->knownIds->toStrings());
    }

    public function testChangedSettingOutsideTheAppsWaitsForARestart(): void
    {
        $config = $this->project->getFilePath('stewart.yaml');
        file_put_contents($config, "log_level: info\n");
        $this->reloads = $this->createReloads(new DaemonLaunchOptions($config));
        file_put_contents($config, "log_level: debug\nmqtt:\n  url: mqtt://broker.invalid:1883\n");
        $this->queueReport(['demo'], (string) hash_file('xxh128', $config));

        $last = $this->reload();

        self::assertSame(ReloadOutcome::PartlyApplied, $last->outcome);
        self::assertSame(['log_level', 'mqtt'], $last->settingsNeedingRestart);
        self::assertSame(['Reloaded, except for settings that only a restart applies'], $this->logger->listMessagesAt('warning'));
    }

    public function testReloadAppliesAnAppLogLevel(): void
    {
        $config = $this->project->getFilePath('stewart.yaml');
        file_put_contents($config, "apps:\n  demo:\n    log_level: debug\n");
        $this->reloads = $this->createReloads(new DaemonLaunchOptions($config));
        $this->queueReport(['demo'], (string) hash_file('xxh128', $config));

        $last = $this->reload();

        self::assertSame(ReloadOutcome::Applied, $last->outcome);
        self::assertTrue($this->reloads->verbosity->admitsLevel(LogLevel::Debug, ResourceScope::forApp(new AppId('demo'))));
        self::assertFalse($this->reloads->verbosity->admitsLevel(LogLevel::Debug, null));
    }

    public function testReloadComparesAgainstTheManifestItBrings(): void
    {
        $session = FakeHaSession::createOpened();
        $session->entityIds = ['light.hall', 'light.landing'];
        $this->reloads = $this->createReloads(session: $session);
        $this->queueReport(['demo'], generated: new GeneratedManifest(GeneratedFormat::VERSION, ['light.hall', 'light.landing'], []));

        $this->reload();

        self::assertFalse($this->reloads->drift->status?->hasDrift(), 'The regenerated classes know the new entity.');
    }

    public function testRequestWhileAReloadRunsIsNotRepeated(): void
    {
        $hold = new Latch();
        $this->commands->hold = $hold;
        $this->queueReport(['demo']);
        $this->reloads->coordinator->startAccepting();

        self::assertSame(ReloadRequestOutcome::Started, $this->reloads->coordinator->requestReload(ReloadTrigger::Watch));
        self::assertSame(ReloadRequestOutcome::AlreadyUnderway, $this->reloads->coordinator->requestReload(ReloadTrigger::Http));

        $hold->open();
        $this->awaitResult();

        self::assertSame(1, $this->state->countResults(ReloadOutcome::Applied));
        self::assertCount(1, $this->commands->commands);
    }

    public function testStoppingCancelsARunningCheck(): void
    {
        $this->commands->hold = new Latch();
        $this->reloads->coordinator->startAccepting();
        $this->reloads->coordinator->requestReload(ReloadTrigger::Control);
        EventLoopTicks::settle();

        $this->reloads->coordinator->stopAccepting();
        EventLoopTicks::settleUntil(fn(): bool => !$this->state->isUnderway());

        self::assertTrue($this->commands->cancelled);
        self::assertNull($this->state->findLastResult());
        self::assertSame(1, $this->spawner->countSpawnedProcesses());
    }

    private function createReloads(DaemonLaunchOptions $launch = new DaemonLaunchOptions(), ?FakeHaSession $session = null): ReloadCoordinatorFixture
    {
        return ReloadCoordinatorFixture::createReloadCoordinator(
            $this->pools,
            commands: $this->commands,
            state: $this->state,
            logger: $this->logger,
            launch: $launch,
            topology: AppTopologyFixture::createTopologyForSlots(self::createSlot(0, 'demo')),
            listener: $this->listener,
            session: $session,
        );
    }

    /** @param list<string> $appIds */
    private function queueReport(array $appIds, string $configFingerprint = self::NO_CONFIG_FILE, ?GeneratedManifest $generated = null): void
    {
        $apps = array_map(static fn(string $id): ReloadCheckApp => new ReloadCheckApp($id, Demo::class), $appIds);
        $this->commands->queueResult(ReloadCoordinatorFixture::CONSOLE_SCRIPT, 0, $this->reports->encodeReport(new ReloadCheckReport($apps, [], [], $configFingerprint, $generated)));
    }

    private function reload(): ReloadResult
    {
        $this->reloads->coordinator->startAccepting();
        self::assertSame(ReloadRequestOutcome::Started, $this->reloads->coordinator->requestReload(ReloadTrigger::Control));

        return $this->awaitResult();
    }

    private function awaitResult(): ReloadResult
    {
        EventLoopTicks::settleUntil(fn(): bool => $this->state->findLastResult() !== null);

        return $this->state->findLastResult() ?? self::fail('The reload never finished.');
    }

    /** @return list<string> */
    private function listRunningAppIds(): array
    {
        $appIds = [];

        foreach ($this->pools->slots->listSlotStates() as $state) {
            foreach ($state->handle?->getAppIds() ?? [] as $appId) {
                $appIds[] = $appId->value;
            }
        }

        sort($appIds);

        return $appIds;
    }

    private static function createSlot(int $workerId, string ...$appIds): WorkerSlot
    {
        return new WorkerSlot(new WorkerId($workerId), AppDefinitionCollection::keyedByAppId(array_map(static fn(string $id): AppDefinition => new AppDefinition(new AppId($id), Demo::class), $appIds)));
    }
}
