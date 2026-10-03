<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Integration\Control;

use Amp\ByteStream\BufferedReader;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Socket;
use Amp\Socket\ConnectException;
use Amp\Socket\ServerSocket;
use Amp\Socket\UnixAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Config\ControlAddress;
use Stewart\Runtime\Config\ProjectRoot;
use Stewart\Runtime\Config\UnixControlAddress;
use Stewart\Runtime\Control\Client\ControlClient;
use Stewart\Runtime\Control\Client\ControlTarget;
use Stewart\Runtime\Control\Protocol\Codec\FrameCodec;
use Stewart\Runtime\Control\Protocol\ControlProtocol;
use Stewart\Runtime\Control\Protocol\Frame\Bye;
use Stewart\Runtime\Control\Protocol\Frame\Rejected;
use Stewart\Runtime\Control\Protocol\Frame\ServerFrame;
use Stewart\Runtime\Control\Protocol\Frame\SnapshotFrame;
use Stewart\Runtime\Control\Protocol\Frame\Welcome;
use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Control\Server\ControlServer;
use Stewart\Runtime\Control\Server\UnixSocketFile;
use Stewart\Runtime\Exception\ControlError;
use Stewart\Runtime\Exception\ControlException;
use Stewart\Runtime\Tests\Fixtures\Control\StubSnapshotSource;
use Stewart\Support\Time\RevoltTimers;
use Stewart\Testing\Exception\AssertsReason;
use Symfony\Component\Filesystem\Filesystem;

use function Amp\async;
use function Amp\delay;

#[CoversClass(ControlClient::class)]
#[CoversClass(ControlException::class)]
final class ControlClientTest extends TestCase
{
    use AssertsReason;

    private const string TOKEN = 'client-test-token';

    private const float CLIENT_READ_WINDOW_SECONDS = 0.1;

    private string $path;

    private StubSnapshotSource $snapshots;

    private ?ControlServer $server = null;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/stw-' . bin2hex(random_bytes(4)) . '.sock';
        $this->snapshots = new StubSnapshotSource();
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        @unlink($this->path);
    }

    public function testWrongTokenIsAConnectionRefused(): void
    {
        $this->listen();

        $e = $this->assertThrowsReason(ControlError::ConnectionRejected, fn() => self::createClient()->fetchSnapshot(new ControlTarget(ControlAddress::parse('unix://' . $this->path), 'wrong'), Duration::seconds(2)));

        self::assertStringContainsString('does not match control.token', $e->getMessage());
    }

    public function testNothingListeningIsAConnectException(): void
    {
        $this->expectException(ConnectException::class);

        self::createClient()->fetchSnapshot($this->createControlTarget(), Duration::seconds(2));
    }

    public function testEveryFetchGetsAFreshSnapshot(): void
    {
        $this->listen();

        $first = self::createClient()->fetchSnapshot($this->createControlTarget(), Duration::seconds(2));
        $second = self::createClient()->fetchSnapshot($this->createControlTarget(), Duration::seconds(2));

        self::assertSame('stub', $first->daemon->version);
        self::assertSame('stub', $second->daemon->version);
        self::assertSame(2, $this->snapshots->taken);
    }

    public function testRelativeAddressResolvesAgainstProjectRoot(): void
    {
        $this->listen();

        $snapshot = self::createClient()->fetchSnapshot(new ControlTarget(ControlAddress::parse('unix://' . basename($this->path)), self::TOKEN), Duration::seconds(2));

        self::assertSame('stub', $snapshot->daemon->version);
    }

    public function testByeInPlaceOfSnapshotIsUnexpectedFrame(): void
    {
        $listener = $this->answerOnceWith([new Welcome(ControlProtocol::VERSION), new Bye('no snapshot today')]);

        try {
            $this->assertThrowsReason(ControlError::UnexpectedFrame, fn() => self::createClient()->fetchSnapshot($this->createControlTarget(), Duration::seconds(2)));
        } finally {
            $listener->close();
        }
    }

    public function testOtherFrameInPlaceOfByeIsUnexpectedFrame(): void
    {
        $listener = $this->answerOnceWith([new Welcome(ControlProtocol::VERSION), new SnapshotFrame($this->snapshots->takeSnapshot()), new Rejected('changed my mind')]);

        try {
            $e = $this->assertThrowsReason(ControlError::UnexpectedFrame, fn() => self::createClient()->fetchSnapshot($this->createControlTarget(), Duration::seconds(2)));
            self::assertSame('a bye', $e->context['expectedFrame'] ?? null);
        } finally {
            $listener->close();
        }
    }

    public function testSnapshotIsReturnedOnlyAfterBye(): void
    {
        $byeAllowed = new DeferredFuture();
        $snapshotSent = new DeferredFuture();
        $listener = $this->answerOnceWith([new Welcome(ControlProtocol::VERSION), new SnapshotFrame($this->snapshots->takeSnapshot()), new Bye('snapshot sent')], $byeAllowed->getFuture(), $snapshotSent);

        try {
            /** @var Future<RuntimeSnapshot> $fetch */
            $fetch = async(fn(): RuntimeSnapshot => self::createClient()->fetchSnapshot($this->createControlTarget(), Duration::seconds(2)));
            $snapshotSent->getFuture()->await();
            delay(self::CLIENT_READ_WINDOW_SECONDS);
            self::assertFalse($fetch->isComplete(), 'The client waits for the bye.');

            $byeAllowed->complete();

            self::assertSame('stub', $fetch->await()->daemon->version);
        } finally {
            $listener->close();
        }
    }

    private static function createClient(): ControlClient
    {
        return new ControlClient(new FrameCodec(FrameCodec::createControlWireMapper()), self::createProjectRoot(), new RevoltTimers());
    }

    private static function createProjectRoot(): ProjectRoot
    {
        return new ProjectRoot(sys_get_temp_dir());
    }

    /**
     * @param non-empty-list<ServerFrame> $frames
     * @param Future<mixed>|null $lastFrameAllowed
     * @param DeferredFuture<mixed>|null $leadingFramesSent
     */
    private function answerOnceWith(array $frames, ?Future $lastFrameAllowed = null, ?DeferredFuture $leadingFramesSent = null): ServerSocket
    {
        $codec = new FrameCodec(FrameCodec::createControlWireMapper());
        $listener = Socket\listen(new UnixAddress($this->path));
        $last = array_pop($frames);

        async(static function () use ($listener, $codec, $frames, $last, $lastFrameAllowed, $leadingFramesSent): void {
            $client = $listener->accept();
            \assert($client !== null);
            new BufferedReader($client)->readUntil("\n");

            foreach ($frames as $frame) {
                $client->write($codec->encodeFrame($frame));
            }

            $leadingFramesSent?->complete();
            $lastFrameAllowed?->await();
            $client->write($codec->encodeFrame($last));
            $client->close();
        })->ignore();

        return $listener;
    }

    private function listen(): void
    {
        $this->server = new ControlServer(
            address: new UnixControlAddress($this->path),
            token: self::TOKEN,
            snapshot: $this->snapshots->takeSnapshot(...),
            deadlines: new RevoltTimers(),
            codec: new FrameCodec(FrameCodec::createControlWireMapper()),
            logger: new NullLogger(),
            socketFile: new UnixSocketFile(new Filesystem(), new NullLogger()),
            projectRoot: self::createProjectRoot(),
        );
        $this->server->start();
    }

    private function createControlTarget(): ControlTarget
    {
        return new ControlTarget(ControlAddress::parse('unix://' . $this->path), self::TOKEN);
    }
}
