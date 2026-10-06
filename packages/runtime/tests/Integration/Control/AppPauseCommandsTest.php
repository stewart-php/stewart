<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Integration\Control;

use Amp\Future;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\OutboxLimits;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Config\ConfigLoader;
use Stewart\Runtime\Console\AppPauseCommand;
use Stewart\Runtime\Console\AppRequestCommand;
use Stewart\Runtime\Console\AppResumeCommand;
use Stewart\Runtime\Console\ControlCommand;
use Stewart\Runtime\Control\Client\ControlClient;
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
use Stewart\Store\GuardedStoreBackend;
use Stewart\Store\StoreDsn;
use Stewart\Store\StoreTiming;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Store\InMemoryStoreBackend;
use Symfony\Component\Console\Tester\CommandTester;

use function Amp\async;

#[CoversClass(AppPauseCommand::class)]
#[CoversClass(AppResumeCommand::class)]
#[CoversClass(ControlCommand::class)]
#[CoversClass(AppRequestCommand::class)]
#[CoversClass(ControlClient::class)]
final class AppPauseCommandsTest extends TestCase
{
    private const string TOKEN = 'pause-test-token';

    private const float WAIT_SECONDS = 5;

    private const string STORE_URL = 'redis://valkey:6379/0';

    private string $path;

    private BootedBroker $broker;

    private InMemoryStoreBackend $store;

    /** @var Future<mixed> */
    private Future $running;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/stw-' . bin2hex(random_bytes(4)) . '.sock';
        $logger = new RecordingLogger();
        $pools = WorkerPoolFixture::createWorkerPool(new InMemoryWorkerSpawner(IpcCodec::createForWorkerBootstrap()), logger: $logger, outboxLimits: new OutboxLimits(100, 256));
        $appId = new AppId('serial-handler');
        $this->store = new InMemoryStoreBackend($pools->clock);
        $this->broker = BrokerKernelFixture::boot(
            new FakeHaSession(),
            $pools,
            WorkerSlotCollection::fromWorkerSlots([new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([new AppDefinition($appId, SerialHandler::class)]))]),
            AppIdCollection::fromIds([$appId, new AppId('dormant')]),
            $logger,
            ['shutdown_grace' => '1s', 'control' => ['listen' => 'unix://' . $this->path, 'token' => self::TOKEN], 'persistence' => ['url' => self::STORE_URL]],
            new SyntheticServices()->withService(GuardedStoreBackend::class, new GuardedStoreBackend($this->store, StoreDsn::parse(self::STORE_URL), new StoreTiming(Duration::seconds(2), Duration::seconds(5)), $pools->clock)),
        );
        $ready = $logger->waitForMessage('Worker ready');
        $this->running = async($this->broker->lifecycle->run(...));
        $ready->await(new TimeoutCancellation(self::WAIT_SECONDS));
    }

    protected function tearDown(): void
    {
        $this->broker->run->stop('test done');
        $this->running->await(new TimeoutCancellation(self::WAIT_SECONDS));
    }

    public function testPauseIsReportedThenReportedAsNoOp(): void
    {
        $tester = $this->createTester(AppPauseCommand::NAME);

        self::assertSame(0, $tester->execute($this->createInput('serial-handler')));
        self::assertSame("App serial-handler paused.\n", $tester->getDisplay());
        self::assertSame(0, $tester->execute($this->createInput('serial-handler')));
        self::assertSame("App serial-handler was already paused.\n", $tester->getDisplay());
    }

    public function testUnsavedPauseStillAppliesWithWarning(): void
    {
        $this->store->simulateOutage('valkey:6379 refused the connection');
        $tester = $this->createTester(AppPauseCommand::NAME);

        self::assertSame(0, $tester->execute($this->createInput('serial-handler'), ['capture_stderr_separately' => true]));
        self::assertSame("App serial-handler paused.\n", $tester->getDisplay());
        self::assertStringContainsString('the store did not take the change', $tester->getErrorOutput());

        $tester->execute($this->createInput('serial-handler'), ['capture_stderr_separately' => true]);
        self::assertSame("App serial-handler was already paused.\n", $tester->getDisplay());
    }

    public function testResumeUnpausesPausedApp(): void
    {
        $this->createTester(AppPauseCommand::NAME)->execute($this->createInput('serial-handler'));
        $tester = $this->createTester(AppResumeCommand::NAME);

        self::assertSame(0, $tester->execute($this->createInput('serial-handler')));
        self::assertSame("App serial-handler resumed.\n", $tester->getDisplay());
        self::assertSame(0, $tester->execute($this->createInput('serial-handler')));
        self::assertSame("App serial-handler was not paused.\n", $tester->getDisplay());
    }

    public function testUnknownAppIsOneLineAndExitOne(): void
    {
        $tester = $this->createTester(AppPauseCommand::NAME);

        self::assertSame(1, $tester->execute($this->createInput('ghost')));
        self::assertStringContainsString('No automation with ID "ghost".', $tester->getDisplay());
    }

    public function testDisabledAppIsOneLineAndExitOne(): void
    {
        $tester = $this->createTester(AppPauseCommand::NAME);

        self::assertSame(1, $tester->execute($this->createInput('dormant')));
        self::assertStringContainsString('apps.dormant.enabled is false', $tester->getDisplay());
    }

    public function testMalformedAppIdFailsBeforeConnecting(): void
    {
        $tester = $this->createTester(AppResumeCommand::NAME);

        self::assertSame(1, $tester->execute($this->createInput('Not An Id')));
        self::assertStringContainsString('Not An Id', $tester->getDisplay());
    }

    /** @return array<string, string> */
    private function createInput(string $appId): array
    {
        return ['app' => $appId, '--address' => 'unix://' . $this->path, '--token' => self::TOKEN];
    }

    private function createTester(string $command): CommandTester
    {
        $application = new ConsoleKernel(sys_get_temp_dir(), [], new SyntheticServices()->withService(ConfigLoader::class, ConfigLoaderFixture::createLoader()))->createApplication();

        return new CommandTester($application->find($command));
    }
}
