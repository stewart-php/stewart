<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Integration\Control;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Runtime\App\AppCatalog;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppPauseRegistry;
use Stewart\Runtime\Broker\AppPauseService;
use Stewart\Runtime\Broker\WorkerSlotRegistry;
use Stewart\Runtime\Config\ConfigLoader;
use Stewart\Runtime\Config\ProjectRoot;
use Stewart\Runtime\Config\UnixControlAddress;
use Stewart\Runtime\Console\AppPauseCommand;
use Stewart\Runtime\Console\AppResumeCommand;
use Stewart\Runtime\Console\ControlCommand;
use Stewart\Runtime\Control\Client\ControlClient;
use Stewart\Runtime\Control\Protocol\Codec\FrameCodec;
use Stewart\Runtime\Control\Request\ControlRequestDispatcher;
use Stewart\Runtime\Control\Request\PauseAppRequestHandler;
use Stewart\Runtime\Control\Request\ResumeAppRequestHandler;
use Stewart\Runtime\Control\Server\ControlServer;
use Stewart\Runtime\Control\Server\UnixSocketFile;
use Stewart\Runtime\Kernel\ConsoleKernel;
use Stewart\Runtime\Kernel\SyntheticServices;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigLoaderFixture;
use Stewart\Support\Time\RevoltTimers;
use Stewart\Testing\Time\VirtualClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(AppPauseCommand::class)]
#[CoversClass(AppResumeCommand::class)]
#[CoversClass(ControlCommand::class)]
#[CoversClass(ControlClient::class)]
final class AppPauseCommandsTest extends TestCase
{
    private const string TOKEN = 'pause-test-token';

    private string $path;

    private AppPauseRegistry $registry;

    private ?ControlServer $server = null;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/stw-' . bin2hex(random_bytes(4)) . '.sock';
        $this->listen();
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        @unlink($this->path);
    }

    public function testPauseIsReportedThenReportedAsNoOp(): void
    {
        $tester = $this->createTester(AppPauseCommand::NAME);

        self::assertSame(0, $tester->execute($this->createInput('demo')));
        self::assertSame("App demo paused.\n", $tester->getDisplay());
        self::assertSame(0, $tester->execute($this->createInput('demo')));
        self::assertSame("App demo was already paused.\n", $tester->getDisplay());
        self::assertTrue($this->registry->isPaused(new AppId('demo')));
    }

    public function testResumeUnpausesPausedApp(): void
    {
        $this->createTester(AppPauseCommand::NAME)->execute($this->createInput('demo'));
        $tester = $this->createTester(AppResumeCommand::NAME);

        self::assertSame(0, $tester->execute($this->createInput('demo')));
        self::assertSame("App demo resumed.\n", $tester->getDisplay());
        self::assertFalse($this->registry->isPaused(new AppId('demo')));
    }

    public function testUnknownAppIsOneLineAndExitOne(): void
    {
        $tester = $this->createTester(AppPauseCommand::NAME);

        self::assertSame(1, $tester->execute($this->createInput('ghost')));
        self::assertStringContainsString('No automation with ID "ghost".', $tester->getDisplay());
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

    private function listen(): void
    {
        $clock = new VirtualClock();
        $apps = AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class)]);
        $this->registry = new AppPauseRegistry($apps, $clock);
        $service = new AppPauseService(
            new AppCatalog($apps, AppIdCollection::fromIds([new AppId('demo')]), AppIdCollection::fromIds([])),
            $this->registry,
            new WorkerSlotRegistry(),
            $clock,
            new NullLogger(),
        );
        $this->server = new ControlServer(
            address: new UnixControlAddress($this->path),
            token: self::TOKEN,
            requests: new ControlRequestDispatcher([new PauseAppRequestHandler($service), new ResumeAppRequestHandler($service)]),
            deadlines: new RevoltTimers(),
            codec: new FrameCodec(FrameCodec::createControlWireMapper()),
            logger: new NullLogger(),
            socketFile: new UnixSocketFile(new Filesystem(), new NullLogger()),
            projectRoot: new ProjectRoot(sys_get_temp_dir()),
        );
        $this->server->start();
    }
}
