<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker\Reload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Broker\DaemonLaunchOptions;
use Stewart\Runtime\Broker\Reload\ReloadCoordinator;
use Stewart\Runtime\Broker\Reload\ReloadTrigger;
use Stewart\Runtime\Broker\Reload\SourcePathFinder;
use Stewart\Runtime\Broker\Reload\SourceScanner;
use Stewart\Runtime\Broker\Reload\SourceWatcher;
use Stewart\Runtime\Config\EnvironmentVariables;
use Stewart\Runtime\Config\ProjectRoot;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeWorkerSpawner;
use Stewart\Runtime\Tests\Fixtures\Broker\ReloadCoordinatorFixture;
use Stewart\Runtime\Tests\Fixtures\Broker\WorkerPoolFixture;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigLoaderFixture;
use Stewart\Runtime\Tests\Fixtures\Deploy\ScriptedCommandRunner;
use Stewart\Testing\Async\Latch;
use Stewart\Testing\Filesystem\TempDirectory;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;

#[CoversClass(SourceWatcher::class)]
final class SourceWatcherTest extends TestCase
{
    private TempDirectory $project;

    private ManualTimers $timers;

    private ScriptedCommandRunner $commands;

    private RecordingLogger $logger;

    private ReloadCoordinator $reloads;

    protected function setUp(): void
    {
        $this->project = TempDirectory::createWithPrefix('stewart-watch-');
        mkdir($this->project->getFilePath('apps'));
        file_put_contents($this->project->getFilePath('composer.json'), '{"autoload": {"psr-4": {"App\\\\": "apps/"}}}');
        file_put_contents($this->project->getFilePath('apps/Porch.php'), "<?php\n");
        file_put_contents($this->project->getFilePath('.env'), "STEWART_LOG_LEVEL=info\n");
        $this->timers = new ManualTimers();
        $this->commands = new ScriptedCommandRunner();
        $this->logger = new RecordingLogger();
        $this->reloads = ReloadCoordinatorFixture::createReloadCoordinator(
            WorkerPoolFixture::createWorkerPool(new FakeWorkerSpawner(), timers: new ManualTimers()),
            commands: $this->commands,
        )->coordinator;
        $this->reloads->startAccepting();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testSavedChangeReloadsOnceItSettles(): void
    {
        $watcher = $this->startWatcher();
        $this->editFile('apps/Porch.php', 10);

        $this->scanOnce();
        self::assertSame([], $this->commands->commands, 'One scan is not enough to know the save is done.');

        $this->scanOnce();
        $this->scanOnce();
        self::assertCount(1, $this->commands->commands);

        $watcher->stopWatching();
    }

    public function testReloadUnderwayIsAskedForAgainOnTheNextScan(): void
    {
        $hold = new Latch();
        $this->commands->hold = $hold;
        $this->reloads->requestReload(ReloadTrigger::Control);
        $watcher = $this->startWatcher();
        $this->editFile('apps/Porch.php', 10);

        $this->scanOnce();
        $this->scanOnce();
        self::assertCount(1, $this->commands->commands, 'The running reload refused the watcher.');

        $hold->open();
        EventLoopTicks::settle();
        $this->scanOnce();
        self::assertCount(2, $this->commands->commands);

        $watcher->stopWatching();
    }

    public function testChangedEnvironmentFileOnlyWarns(): void
    {
        $watcher = $this->startWatcher();
        $this->editFile('.env', 10);

        $this->scanOnce();
        $this->scanOnce();

        self::assertSame([], $this->commands->commands);
        self::assertSame(['A changed file needs a restart of Stewart; a reload does not apply it'], $this->logger->listMessagesAt('warning'));

        $watcher->stopWatching();
    }

    public function testNothingIsWatchedWithoutTheOption(): void
    {
        $this->startWatcher(new DaemonLaunchOptions());

        self::assertSame(0, $this->timers->countPendingTimers());
    }

    private function startWatcher(DaemonLaunchOptions $launch = new DaemonLaunchOptions(watch: true)): SourceWatcher
    {
        $root = new ProjectRoot($this->project->path);
        $watcher = new SourceWatcher(
            $launch,
            new SourcePathFinder($root, ConfigLoaderFixture::createLoader(new EnvironmentVariables(), $root), $launch, $this->project->getFilePath('services.php')),
            new SourceScanner(),
            $this->reloads,
            $this->timers,
            $this->logger,
        );
        $watcher->startWatching();

        return $watcher;
    }

    private function editFile(string $name, int $secondsLater): void
    {
        $path = $this->project->getFilePath($name);
        touch($path, (int) filemtime($path) + $secondsLater);
    }

    private function scanOnce(): void
    {
        $this->timers->delay(Duration::seconds(1));
        EventLoopTicks::settle();
    }
}
