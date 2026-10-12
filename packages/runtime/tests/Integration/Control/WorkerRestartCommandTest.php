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
use Stewart\Runtime\Broker\OutboxLimits;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Config\ConfigLoader;
use Stewart\Runtime\Console\WorkerRestartCommand;
use Stewart\Runtime\Control\Request\RestartWorkerRequestHandler;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Stewart\Runtime\Kernel\ConsoleKernel;
use Stewart\Runtime\Kernel\SyntheticServices;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Broker\BootedBroker;
use Stewart\Runtime\Tests\Fixtures\Broker\BrokerKernelFixture;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Runtime\Tests\Fixtures\Broker\WorkerPoolFixture;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigLoaderFixture;
use Stewart\Runtime\Tests\Fixtures\Protocol\SerialHandler;
use Stewart\Runtime\Tests\Fixtures\Worker\InMemoryWorkerSpawner;
use Stewart\Testing\Logging\RecordedLog;
use Stewart\Testing\Logging\RecordingLogger;
use Symfony\Component\Console\Tester\CommandTester;

use function Amp\async;
use function Amp\delay;

#[CoversClass(WorkerRestartCommand::class)]
#[CoversClass(RestartWorkerRequestHandler::class)]
final class WorkerRestartCommandTest extends TestCase
{
    private const string TOKEN = 'restart-test-token';

    private const float WAIT_SECONDS = 5;

    private string $path;

    private RecordingLogger $logger;

    private WorkerPoolFixture $pools;

    private BootedBroker $broker;

    /** @var Future<mixed> */
    private Future $running;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/stw-' . bin2hex(random_bytes(4)) . '.sock';
        $this->logger = new RecordingLogger();
        $this->pools = $pools = WorkerPoolFixture::createWorkerPool(new InMemoryWorkerSpawner(IpcCodec::createForWorkerBootstrap()), logger: $this->logger, outboxLimits: new OutboxLimits(100, 256));
        $appId = new AppId('serial-handler');
        $this->broker = BrokerKernelFixture::boot(
            new FakeHaSession(),
            $pools,
            WorkerSlotCollection::fromList([new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([new AppDefinition($appId, SerialHandler::class)]))]),
            AppIdCollection::fromList([$appId]),
            $this->logger,
            ['shutdown_grace' => '1s', 'control' => ['listen' => 'unix://' . $this->path, 'token' => self::TOKEN]],
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

    public function testRestartedWorkerComesBackReady(): void
    {
        $tester = $this->createTester();

        self::assertSame(0, $tester->execute($this->createInput('0')));
        self::assertSame("Worker 0 is restarting.\n", $tester->getDisplay());

        $deadline = new TimeoutCancellation(self::WAIT_SECONDS);

        while ($this->countWorkerReadyLogs() < 2) {
            delay(0.01, cancellation: $deadline);
        }

        self::assertSame(1, $this->pools->slots->findSlotState(new WorkerId(0))?->restarts);
    }

    public function testUnknownWorkerIsOneLineAndExitOne(): void
    {
        $tester = $this->createTester();

        foreach (['7', 'porch'] as $workerId) {
            self::assertSame(1, $tester->execute($this->createInput($workerId)));
            self::assertStringContainsString(\sprintf('No worker "%s"', $workerId), $tester->getDisplay());
        }
    }

    private function countWorkerReadyLogs(): int
    {
        return $this->logger->records->filter(static fn(RecordedLog $record): bool => $record->message === 'Worker ready')->count();
    }

    /** @return array<string, string> */
    private function createInput(string $workerId): array
    {
        return ['worker' => $workerId, '--address' => 'unix://' . $this->path, '--token' => self::TOKEN];
    }

    private function createTester(): CommandTester
    {
        $application = new ConsoleKernel(sys_get_temp_dir(), [], new SyntheticServices()->withService(ConfigLoader::class, ConfigLoaderFixture::createLoader()))->createApplication();

        return new CommandTester($application->find(WorkerRestartCommand::NAME));
    }
}
