<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Integration\Control;

use Amp\Future;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\Deploy\ExternalCommandRunner;
use Stewart\Runtime\Broker\OutboxLimits;
use Stewart\Runtime\Broker\Reload\ReloadState;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Config\ConfigLoader;
use Stewart\Runtime\Console\ReloadCommand;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Stewart\Runtime\Kernel\ConsoleKernel;
use Stewart\Runtime\Kernel\SyntheticServices;
use Stewart\Runtime\Lifecycle\ReloadOutcome;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Broker\BootedBroker;
use Stewart\Runtime\Tests\Fixtures\Broker\BrokerKernelFixture;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Runtime\Tests\Fixtures\Broker\ReloadCoordinatorFixture;
use Stewart\Runtime\Tests\Fixtures\Broker\WorkerPoolFixture;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigLoaderFixture;
use Stewart\Runtime\Tests\Fixtures\Deploy\ScriptedCommandRunner;
use Stewart\Runtime\Tests\Fixtures\Protocol\SerialHandler;
use Stewart\Runtime\Tests\Fixtures\Worker\InMemoryWorkerSpawner;
use Stewart\Testing\Async\Latch;
use Stewart\Testing\Logging\RecordedLog;
use Stewart\Testing\Logging\RecordingLogger;
use Symfony\Component\Console\Tester\CommandTester;

use function Amp\async;
use function Amp\delay;

#[CoversClass(ReloadCommand::class)]
final class ReloadCommandTest extends TestCase
{
    private const string TOKEN = 'reload-test-token';

    private const float WAIT_SECONDS = 5;

    private string $path;

    private RecordingLogger $logger;

    private WorkerPoolFixture $pools;

    private ScriptedCommandRunner $commands;

    private ReloadState $reloads;

    private BootedBroker $broker;

    /** @var Future<mixed> */
    private Future $running;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/stw-' . bin2hex(random_bytes(4)) . '.sock';
        $this->logger = new RecordingLogger();
        $this->commands = new ScriptedCommandRunner();
        $this->reloads = new ReloadState();
        $this->pools = WorkerPoolFixture::createWorkerPool(new InMemoryWorkerSpawner(IpcCodec::createForWorkerBootstrap()), logger: $this->logger, outboxLimits: new OutboxLimits(100, 256));
        $appId = new AppId('serial-handler');
        $this->broker = BrokerKernelFixture::boot(
            new FakeHaSession(),
            $this->pools,
            WorkerSlotCollection::fromList([new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([new AppDefinition($appId, SerialHandler::class)]))]),
            AppIdCollection::fromList([$appId]),
            $this->logger,
            ['shutdown_grace' => '1s', 'control' => ['listen' => 'unix://' . $this->path, 'token' => self::TOKEN]],
            new SyntheticServices()
                ->withService(ExternalCommandRunner::class, $this->commands)
                ->withService(ReloadState::class, $this->reloads),
        );
        $ready = $this->logger->waitForMessage('Worker ready');
        $this->running = async($this->broker->lifecycle->run(...));
        $ready->await(new TimeoutCancellation(self::WAIT_SECONDS));
    }

    protected function tearDown(): void
    {
        $this->broker->run->stop('test done');
        $this->running->await(new TimeoutCancellation(self::WAIT_SECONDS));
    }

    public function testReloadChecksThenRestartsTheWorkers(): void
    {
        self::assertSame(0, $this->createTester()->execute($this->createInput()));
        $this->awaitReloadResult();

        while ($this->countWorkerReadyLogs() < 2) {
            delay(0.01, cancellation: new TimeoutCancellation(self::WAIT_SECONDS));
        }

        self::assertSame(ReloadOutcome::Applied, $this->reloads->findLastResult()?->outcome);
        self::assertSame([ReloadCoordinatorFixture::CONSOLE_SCRIPT, 'check'], [$this->commands->commands[0][1], $this->commands->commands[0][2]]);
        self::assertSame(0, $this->pools->slots->findSlotState(new WorkerId(0))?->restarts);
    }

    public function testSecondReloadWhileOneRunsIsRefused(): void
    {
        $this->commands->hold = new Latch();
        $tester = $this->createTester();

        $tester->execute($this->createInput());
        $tester->execute($this->createInput());

        self::assertSame("A reload is already underway.\n", $tester->getDisplay());

        $this->commands->hold->open();
        $this->awaitReloadResult();
    }

    public function testFailedCheckLeavesTheWorkerRunning(): void
    {
        $this->commands->queueResult(ReloadCoordinatorFixture::CONSOLE_SCRIPT, 1, '', "apps/Porch.php failed to load: syntax error\n");

        $this->createTester()->execute($this->createInput());
        $this->awaitReloadResult();

        self::assertSame(ReloadOutcome::Failed, $this->reloads->findLastResult()?->outcome);
        self::assertSame(1, $this->countWorkerReadyLogs());
    }

    private function awaitReloadResult(): void
    {
        $deadline = new TimeoutCancellation(self::WAIT_SECONDS);

        while ($this->reloads->findLastResult() === null) {
            delay(0.01, cancellation: $deadline);
        }
    }

    private function countWorkerReadyLogs(): int
    {
        return $this->logger->records->filter(static fn(RecordedLog $record): bool => $record->message === 'Worker ready')->count();
    }

    /** @return array<string, string> */
    private function createInput(): array
    {
        return ['--address' => 'unix://' . $this->path, '--token' => self::TOKEN];
    }

    private function createTester(): CommandTester
    {
        $application = new ConsoleKernel(sys_get_temp_dir(), [], new SyntheticServices()->withService(ConfigLoader::class, ConfigLoaderFixture::createLoader()))->createApplication();

        return new CommandTester($application->find(ReloadCommand::NAME));
    }
}
