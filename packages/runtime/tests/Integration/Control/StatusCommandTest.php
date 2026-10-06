<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Integration\Control;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Runtime\Config\ConfigLoader;
use Stewart\Runtime\Config\ProjectRoot;
use Stewart\Runtime\Config\UnixControlAddress;
use Stewart\Runtime\Console\ProbeKind;
use Stewart\Runtime\Console\StatusCommand;
use Stewart\Runtime\Console\StatusRenderer;
use Stewart\Runtime\Control\Client\ControlClient;
use Stewart\Runtime\Control\Protocol\Codec\FrameCodec;
use Stewart\Runtime\Control\Protocol\Frame\SnapshotFrame;
use Stewart\Runtime\Control\Request\ControlRequestDispatcher;
use Stewart\Runtime\Control\Server\ControlServer;
use Stewart\Runtime\Control\Server\UnixSocketFile;
use Stewart\Runtime\Kernel\ConsoleKernel;
use Stewart\Runtime\Kernel\SyntheticServices;
use Stewart\Runtime\Lifecycle\ConnectionPhase;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigLoaderFixture;
use Stewart\Runtime\Tests\Fixtures\Control\StubSnapshotRequestHandler;
use Stewart\Runtime\Tests\Fixtures\Control\StubSnapshotSource;
use Stewart\Support\Time\RevoltTimers;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(StatusCommand::class)]
#[CoversClass(ControlClient::class)]
#[CoversClass(StatusRenderer::class)]
#[CoversClass(ProbeKind::class)]
final class StatusCommandTest extends TestCase
{
    private const string TOKEN = 'status-test-token';

    private const string GOLDEN = __DIR__ . '/../../Fixtures/Control/Frames/snapshot.json';

    private string $path;

    private ?ControlServer $server = null;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/stw-' . bin2hex(random_bytes(4)) . '.sock';
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        @unlink($this->path);
    }

    public function testTablesComeOutWhenTheDaemonAnswers(): void
    {
        $this->listen();
        $tester = $this->tester();

        $exit = $tester->execute(['--address' => 'unix://' . $this->path, '--token' => self::TOKEN]);

        self::assertSame(0, $exit, $tester->getDisplay());
        self::assertStringContainsString('Daemon', $tester->getDisplay());
        self::assertStringContainsString('stub', $tester->getDisplay());
        self::assertStringContainsString('Workers', $tester->getDisplay());
        self::assertStringContainsString('porch', $tester->getDisplay());
        self::assertStringContainsString('10/2', $tester->getDisplay());
        self::assertStringContainsString('≤ 25 ms', $tester->getDisplay());
        self::assertStringContainsString('boom', $tester->getDisplay());
    }

    public function testJsonIsTheWireSnapshotUnchanged(): void
    {
        $this->listen();
        $tester = $this->tester();

        $exit = $tester->execute(['--address' => 'unix://' . $this->path, '--token' => self::TOKEN, '--json' => true]);

        self::assertSame(0, $exit, $tester->getDisplay());
        $frame = new FrameCodec(FrameCodec::createControlWireMapper())->decodeServerFrame($tester->getDisplay());
        self::assertInstanceOf(SnapshotFrame::class, $frame);
        self::assertSame('stub', $frame->snapshot->daemon->version);
    }

    public function testJsonMatchesTheGoldenShape(): void
    {
        $this->listen();
        $tester = $this->tester();

        $tester->execute(['--address' => 'unix://' . $this->path, '--token' => self::TOKEN, '--json' => true]);

        self::assertSame(
            json_decode((string) file_get_contents(self::GOLDEN), true, flags: \JSON_THROW_ON_ERROR),
            json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    public function testNothingListeningIsOneLineAndExitOne(): void
    {
        $tester = $this->tester();

        $exit = $tester->execute(['--address' => 'unix://' . $this->path, '--token' => self::TOKEN, '--timeout' => '1s']);

        self::assertSame(1, $exit);
        self::assertStringContainsString($this->path, $tester->getDisplay());
    }

    public function testWrongTokenIsOneLineAndExitOne(): void
    {
        $this->listen();
        $tester = $this->tester();

        $exit = $tester->execute(['--address' => 'unix://' . $this->path, '--token' => 'wrong']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('does not match control.token', $tester->getDisplay());
    }

    public function testTimeoutWithoutAUnitIsOneLineAndExitOne(): void
    {
        $tester = $this->tester();

        $exit = $tester->execute(['--address' => 'unix://' . $this->path, '--token' => self::TOKEN, '--timeout' => '1']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('Duration "1" is invalid', $tester->getDisplay());
    }

    public function testReadinessProbePassesWhenConnected(): void
    {
        $this->listen();
        $tester = $this->tester();

        $exit = $tester->execute(['--address' => 'unix://' . $this->path, '--token' => self::TOKEN, '--probe' => 'readiness']);

        self::assertSame(0, $exit);
        self::assertSame("ready\n", $tester->getDisplay());
    }

    public function testReadinessProbeFailsWhileReconnecting(): void
    {
        $this->listen(new StubSnapshotSource(connectionPhase: ConnectionPhase::Reconnecting));
        $tester = $this->tester();

        $exit = $tester->execute(['--address' => 'unix://' . $this->path, '--token' => self::TOKEN, '--probe' => 'readiness']);

        self::assertSame(1, $exit);
        self::assertSame("not ready: Home Assistant is reconnecting\n", $tester->getDisplay());
    }

    public function testLivenessProbePassesWhileReconnecting(): void
    {
        $this->listen(new StubSnapshotSource(connectionPhase: ConnectionPhase::Reconnecting));
        $tester = $this->tester();

        $exit = $tester->execute(['--address' => 'unix://' . $this->path, '--token' => self::TOKEN, '--probe' => 'liveness']);

        self::assertSame(0, $exit);
        self::assertSame("alive\n", $tester->getDisplay());
    }

    public function testUnknownProbeIsOneLineAndExitOne(): void
    {
        $tester = $this->tester();

        $exit = $tester->execute(['--address' => 'unix://' . $this->path, '--token' => self::TOKEN, '--probe' => 'startup']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('"startup" is not liveness or readiness', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        $application = new ConsoleKernel(sys_get_temp_dir(), [], new SyntheticServices()->withService(ConfigLoader::class, ConfigLoaderFixture::createLoader()))->createApplication();

        return new CommandTester($application->find(StatusCommand::NAME));
    }

    private function listen(StubSnapshotSource $snapshots = new StubSnapshotSource()): void
    {
        $this->server = new ControlServer(
            address: new UnixControlAddress($this->path),
            token: self::TOKEN,
            requests: new ControlRequestDispatcher([new StubSnapshotRequestHandler($snapshots)]),
            deadlines: new RevoltTimers(),
            codec: new FrameCodec(FrameCodec::createControlWireMapper()),
            logger: new NullLogger(),
            socketFile: new UnixSocketFile(new Filesystem(), new NullLogger()),
            projectRoot: new ProjectRoot(sys_get_temp_dir()),
        );
        $this->server->start();
    }
}
