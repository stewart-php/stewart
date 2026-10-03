<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Control;

use Amp\ByteStream\BufferedReader;
use Amp\Socket;
use Amp\Socket\Socket as ClientSocket;
use Amp\Socket\UnixAddress;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Broker\DisabledControlPlane;
use Stewart\Runtime\Config\ProjectRoot;
use Stewart\Runtime\Config\UnixControlAddress;
use Stewart\Runtime\Control\Protocol\Codec\FrameCodec;
use Stewart\Runtime\Control\Protocol\ControlProtocol;
use Stewart\Runtime\Control\Protocol\Frame\Bye;
use Stewart\Runtime\Control\Protocol\Frame\Hello;
use Stewart\Runtime\Control\Protocol\Frame\Rejected;
use Stewart\Runtime\Control\Protocol\Frame\ServerFrame;
use Stewart\Runtime\Control\Protocol\Frame\SnapshotFrame;
use Stewart\Runtime\Control\Protocol\Frame\Welcome;
use Stewart\Runtime\Control\Server\ClientSession;
use Stewart\Runtime\Control\Server\ControlServer;
use Stewart\Runtime\Control\Server\UnixSocketFile;
use Stewart\Runtime\Exception\ControlError;
use Stewart\Runtime\Exception\ControlException;
use Stewart\Runtime\Tests\Fixtures\Control\StubSnapshotSource;
use Stewart\Support\Time\Deadlines;
use Stewart\Support\Time\RevoltTimers;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(ControlServer::class)]
#[CoversClass(ClientSession::class)]
#[CoversClass(UnixSocketFile::class)]
#[CoversClass(ControlException::class)]
#[CoversClass(DisabledControlPlane::class)]
final class ControlServerTest extends TestCase
{
    private const string TOKEN = 'unit-test-token';

    private const int LARGER_THAN_SOCKET_BUFFER = 1_000_000;

    private const string DROPPED = 'Dropped a control client that did not finish in time';

    private string $path;

    private FrameCodec $codec;

    private StubSnapshotSource $snapshots;

    private RecordingLogger $logger;

    private ?ControlServer $server = null;

    /** @var array<int, BufferedReader> */
    private array $readers = [];

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/stw-' . bin2hex(random_bytes(4)) . '.sock';
        $this->codec = new FrameCodec(FrameCodec::createControlWireMapper());
        $this->snapshots = new StubSnapshotSource();
        $this->logger = new RecordingLogger();
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        @unlink($this->path);
    }

    public function testWrongTokenIsRejectedAndTheConnectionClosed(): void
    {
        $this->listen();
        $socket = $this->connect();
        $socket->write($this->codec->encodeFrame(new Hello('nope', 'phpunit')));

        $frame = $this->read($socket);
        self::assertInstanceOf(Rejected::class, $frame);
        self::assertNull($socket->read(new TimeoutCancellation(1.0)), 'Nothing follows a rejection.');
        self::assertContains('Refused a control connection with the wrong token', $this->logger->listMessagesAt('warning'));
    }

    public function testClientOnAnotherProtocolIsToldBothVersions(): void
    {
        $this->listen();
        $socket = $this->connect();
        $socket->write($this->codec->encodeFrame(new Hello(self::TOKEN, 'phpunit', ControlProtocol::VERSION - 1)));

        $frame = $this->read($socket);
        self::assertInstanceOf(Rejected::class, $frame);
        self::assertStringContainsString(\sprintf('protocol %d and the client speaks %d', ControlProtocol::VERSION, ControlProtocol::VERSION - 1), $frame->reason);
        self::assertNull($socket->read(new TimeoutCancellation(1.0)), 'Nothing follows a rejection.');
    }

    public function testClientWithoutHelloIsDroppedAfterDeadline(): void
    {
        $timers = new ManualTimers();
        $this->listen(sessionTimeout: 0.1, deadlines: $timers);
        $socket = $this->connect();
        self::awaitSessionDeadline($timers);
        $timers->delay(Duration::milliseconds(100));

        self::assertNull($socket->read(new TimeoutCancellation(1.0)));
        self::assertContains('Refused a control connection that did not say hello', $this->logger->listMessagesAt('warning'));
    }

    public function testTooManyClientsAreRefusedWithAWarning(): void
    {
        $this->listen();
        $accepted = [];

        for ($i = 0; $i < 8; ++$i) {
            $accepted[] = $this->connect();
        }

        $extra = $this->connect();

        self::assertNull($extra->read(new TimeoutCancellation(1.0)));
        self::assertContains('Control client refused: too many clients', $this->logger->listMessagesAt('warning'));
        self::assertCount(8, $accepted);
    }

    public function testGoodHelloGetsAWelcomeOneSnapshotAndBye(): void
    {
        $this->listen();
        $socket = $this->connect();
        $socket->write($this->codec->encodeFrame(new Hello(self::TOKEN, 'phpunit')));

        $welcome = $this->read($socket);
        self::assertInstanceOf(Welcome::class, $welcome);
        self::assertSame(ControlProtocol::VERSION, $welcome->protocol);

        $snapshot = $this->read($socket);
        self::assertInstanceOf(SnapshotFrame::class, $snapshot);
        self::assertSame('stub', $snapshot->snapshot->daemon->version);

        self::assertInstanceOf(Bye::class, $this->read($socket));
        self::assertNull($socket->read(new TimeoutCancellation(1.0)), 'The session ends after the snapshot.');
        self::assertSame(1, $this->snapshots->taken);
    }

    public function testOversizedHelloIsRefused(): void
    {
        $this->listen();
        $socket = $this->connect();
        $socket->write(str_repeat('x', 70000));

        self::assertNull($socket->read(new TimeoutCancellation(2.0)));
    }

    public function testStaleSocketIsReclaimedOnStart(): void
    {
        self::leaveStaleSocketAt($this->path);

        $this->listen();

        self::assertContains('Control socket listening', $this->logger->listMessagesAt('info'));
    }

    public function testLiveSocketIsKeptAndStartFails(): void
    {
        $this->listen();

        try {
            $this->createServer(sessionTimeout: 5.0)->start();
            self::fail('Two daemons must not share a socket.');
        } catch (ControlException $e) {
            self::assertSame(ControlError::SocketInUse, $e->reason);
        }

        self::assertInstanceOf(Welcome::class, $this->greet($this->connect()));
    }

    public function testRegularFileAtSocketPathIsKept(): void
    {
        file_put_contents($this->path, 'not a socket');

        try {
            $this->listen();
            self::fail('A regular file must not be replaced by the socket.');
        } catch (ControlException $e) {
            self::assertSame(ControlError::SocketPathNotASocket, $e->reason);
        }

        self::assertStringEqualsFile($this->path, 'not a socket');
    }

    public function testCreatedSocketDirectoryIsOwnerOnly(): void
    {
        $directory = sys_get_temp_dir() . '/stw-' . bin2hex(random_bytes(4));
        $this->path = $directory . '/run/control.sock';

        try {
            $this->listen();

            self::assertSame(0o700, fileperms($directory . '/run') & 0o777);
            self::assertSame(0o600, fileperms($this->path) & 0o777);
        } finally {
            $this->server?->stop();
            $this->server = null;
            new Filesystem()->remove($directory);
        }
    }

    public function testWrongTokenIsRejectedBeforeProtocolCheck(): void
    {
        $this->listen();
        $socket = $this->connect();
        $socket->write($this->codec->encodeFrame(new Hello('nope', 'phpunit', ControlProtocol::VERSION - 1)));

        $frame = $this->read($socket);
        self::assertInstanceOf(Rejected::class, $frame);
        self::assertSame('the token does not match control.token', $frame->reason);
    }

    public function testUnremovableStaleSocketFailsStart(): void
    {
        $directory = sys_get_temp_dir() . '/stw-' . bin2hex(random_bytes(4));
        mkdir($directory);
        $this->path = $directory . '/control.sock';
        self::leaveStaleSocketAt($this->path);
        chmod($directory, 0o500);

        if (is_writable($directory)) {
            chmod($directory, 0o700);
            new Filesystem()->remove($directory);
            self::markTestSkipped('Root can remove files from a read-only directory.');
        }

        try {
            $this->createServer(sessionTimeout: 5.0)->start();
            self::fail('A stale socket that cannot be removed must stop the start.');
        } catch (ControlException $e) {
            self::assertSame(ControlError::SocketUnremovable, $e->reason);
        } finally {
            chmod($directory, 0o700);
            new Filesystem()->remove($directory);
        }
    }

    public function testStopClosesClientsAndRemovesSocketFile(): void
    {
        $this->listen();
        $socket = $this->connect();

        $this->server?->stop();
        $this->server = null;

        self::assertNull($socket->read(new TimeoutCancellation(1.0)));
        self::assertFileDoesNotExist($this->path);
    }

    public function testStopKeepsSocketAnotherServerBound(): void
    {
        $this->listen();
        $replaced = $this->server;
        unlink($this->path);
        $this->server = $this->createServer(sessionTimeout: 5.0);
        $this->server->start();

        $replaced?->stop();

        self::assertFileExists($this->path);
        self::assertInstanceOf(Welcome::class, $this->greet($this->connect()));
        self::assertContains('Left the control socket in place because another process replaced it', $this->logger->listMessagesAt('warning'));
    }

    public function testClientThatStopsReadingIsDroppedAtDeadline(): void
    {
        $timers = new ManualTimers();
        $this->snapshots = new StubSnapshotSource(str_repeat('x', self::LARGER_THAN_SOCKET_BUFFER));
        $this->listen(sessionTimeout: 0.1, deadlines: $timers);
        $stalled = $this->connect();
        self::awaitSessionDeadline($timers);
        $stalled->write($this->codec->encodeFrame(new Hello(self::TOKEN, 'phpunit')));
        EventLoopTicks::settleUntil(fn(): bool => $this->snapshots->taken === 1, 100);

        $timers->delay(Duration::milliseconds(100));
        EventLoopTicks::settleUntil(fn(): bool => \in_array(self::DROPPED, $this->logger->listMessagesAt('warning'), true), 100);

        self::assertContains(self::DROPPED, $this->logger->listMessagesAt('warning'));
        self::assertNotContains('Could not answer a control client', $this->logger->listMessagesAt('warning'));
    }

    public function testDisabledPlaneDoesNothing(): void
    {
        $this->expectNotToPerformAssertions();

        $plane = new DisabledControlPlane();
        $plane->start();
        $plane->stop();
    }

    private function listen(float $sessionTimeout = 5.0, Deadlines $deadlines = new RevoltTimers()): void
    {
        $this->server = $this->createServer($sessionTimeout, $deadlines);
        $this->server->start();
    }

    private function createServer(float $sessionTimeout, Deadlines $deadlines = new RevoltTimers()): ControlServer
    {
        return new ControlServer(
            address: new UnixControlAddress($this->path),
            token: self::TOKEN,
            snapshot: $this->snapshots->takeSnapshot(...),
            deadlines: $deadlines,
            codec: $this->codec,
            logger: $this->logger,
            socketFile: new UnixSocketFile(new Filesystem(), $this->logger),
            projectRoot: new ProjectRoot(sys_get_temp_dir()),
            sessionTimeout: Duration::seconds($sessionTimeout),
        );
    }

    private static function leaveStaleSocketAt(string $path): void
    {
        $listener = stream_socket_server('unix://' . $path);
        self::assertIsResource($listener);
        fclose($listener);
    }

    private function greet(ClientSocket $socket): ServerFrame
    {
        $socket->write($this->codec->encodeFrame(new Hello(self::TOKEN, 'phpunit')));

        return $this->read($socket);
    }

    private static function awaitSessionDeadline(ManualTimers $timers): void
    {
        EventLoopTicks::settleUntil(static fn(): bool => $timers->countPendingTimers() > 0, 100);
    }

    private function connect(): ClientSocket
    {
        return Socket\connect(new UnixAddress($this->path), null, new TimeoutCancellation(2.0));
    }

    private function read(ClientSocket $socket): ServerFrame
    {
        $reader = $this->readers[spl_object_id($socket)] ??= new BufferedReader($socket);

        return $this->codec->decodeServerFrame($reader->readUntil("\n", new TimeoutCancellation(2.0)));
    }
}
