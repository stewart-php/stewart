<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Server;

use Amp\Socket;
use Amp\Socket\ResourceServerSocket;
use Amp\Socket\SocketAddress;
use Amp\Socket\UnixAddress;
use Closure;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Broker\ControlPlane;
use Stewart\Runtime\Config\ControlAddress;
use Stewart\Runtime\Config\ProjectRoot;
use Stewart\Runtime\Config\UnixControlAddress;
use Stewart\Runtime\Control\Protocol\Codec\FrameCodec;
use Stewart\Runtime\Control\Protocol\ControlProtocol;
use Stewart\Runtime\Control\Protocol\Frame\Bye;
use Stewart\Runtime\Control\Protocol\Frame\Hello;
use Stewart\Runtime\Control\Protocol\Frame\Rejected;
use Stewart\Runtime\Control\Protocol\Frame\SnapshotFrame;
use Stewart\Runtime\Control\Protocol\Frame\Welcome;
use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Exception\ConfigurationException;
use Stewart\Runtime\Exception\ControlException;
use Stewart\Support\Time\Deadlines;
use Throwable;

use function Amp\async;

final class ControlServer implements ControlPlane
{
    private const int LINE_LIMIT = 65536;

    private const int MAX_CLIENTS = 8;

    private ?ResourceServerSocket $server = null;

    /** @var array<int, ClientSession> */
    private array $sessions = [];

    private int $nextClientId = 0;

    private ?BoundSocketFile $boundSocketFile = null;

    private readonly Duration $sessionTimeout;

    /** @param Closure(): RuntimeSnapshot $snapshot */
    public function __construct(
        private readonly ControlAddress $address,
        #[SensitiveParameter]
        private readonly string $token,
        private readonly Closure $snapshot,
        private readonly Deadlines $deadlines,
        private readonly FrameCodec $codec,
        private readonly LoggerInterface $logger,
        private readonly UnixSocketFile $socketFile,
        private readonly ProjectRoot $projectRoot,
        ?Duration $sessionTimeout = null,
    ) {
        $this->sessionTimeout = $sessionTimeout ?? Duration::seconds(5);
    }

    /** @throws ControlException|ConfigurationException */
    public function start(): void
    {
        $bindAddress = $this->prepareBindAddress();
        // umask instead of chmod: the socket is never readable by other users, not even briefly.
        $mask = umask(0o177);

        try {
            $this->server = Socket\listen($bindAddress);
        } finally {
            umask($mask);
        }

        if ($bindAddress instanceof UnixAddress) {
            $this->boundSocketFile = $this->socketFile->identifyBoundSocket($bindAddress->toString());
        }

        $this->server->unreference();

        async($this->acceptClients(...))->ignore();

        $this->logger->info('Control socket listening', ['address' => (string) $this->address]);

        if (!$this->address->isLoopback()) {
            $this->logger->warning('The control socket accepts connections from other hosts; anyone with the token can read the runtime snapshot', [
                'address' => (string) $this->address,
            ]);
        }
    }

    public function stop(): void
    {
        $server = $this->server;
        $this->server = null;
        $server?->close();

        foreach ($this->sessions as $session) {
            $session->close();
        }

        $this->sessions = [];

        if ($this->boundSocketFile !== null) {
            $this->socketFile->removeIfStillBound($this->boundSocketFile);
            $this->boundSocketFile = null;
        }
    }

    private function acceptClients(): void
    {
        $server = $this->server;

        if ($server === null) {
            return;
        }

        try {
            while (($socket = $server->accept()) !== null) {
                if (\count($this->sessions) >= self::MAX_CLIENTS) {
                    $this->logger->warning('Control client refused: too many clients', ['limit' => self::MAX_CLIENTS]);
                    $socket->close();

                    continue;
                }

                $session = new ClientSession(++$this->nextClientId, $socket, $this->codec, $this->deadlines->timeout($this->sessionTimeout));
                $this->sessions[$session->id] = $session;
                async($this->serveClient(...), $session)->ignore();
            }
        } catch (Throwable $e) {
            if ($this->server !== null) {
                $this->logger->error('Control socket stopped accepting connections', ['exception' => $e]);
            }
        }
    }

    private function serveClient(ClientSession $session): void
    {
        try {
            $hello = $this->awaitHello($session);

            if ($hello === null) {
                return;
            }

            $session->send(new Welcome(ControlProtocol::VERSION));
            $session->send(new SnapshotFrame(($this->snapshot)()));
            $session->send(new Bye('snapshot sent'));
            $this->logger->debug('Answered a control client', ['client' => $hello->client, 'peer' => $session->describePeer()]);
        } catch (Throwable $e) {
            if ($session->hasExpired()) {
                $this->logger->warning('Dropped a control client that did not finish in time', ['peer' => $session->describePeer(), 'timeout' => (string) $this->sessionTimeout]);
            } else {
                $this->logger->warning('Could not answer a control client', ['peer' => $session->describePeer(), 'exception' => $e]);
            }
        } finally {
            unset($this->sessions[$session->id]);
            $session->close();
        }
    }

    private function awaitHello(ClientSession $session): ?Hello
    {
        try {
            $frame = $this->codec->decodeClientFrame($session->readLine(self::LINE_LIMIT));
        } catch (Throwable $e) {
            $this->logger->warning('Refused a control connection that did not say hello', ['peer' => $session->describePeer(), 'exception' => $e]);

            return null;
        }

        if (!$frame instanceof Hello || !hash_equals($this->token, $frame->token)) {
            $this->logger->warning('Refused a control connection with the wrong token', ['peer' => $session->describePeer()]);
            $session->send(new Rejected('the token does not match control.token'));

            return null;
        }

        if ($frame->protocol !== ControlProtocol::VERSION) {
            $this->logger->warning('Refused a control client that speaks another protocol', ['peer' => $session->describePeer(), 'protocol' => $frame->protocol]);
            $session->send(new Rejected(\sprintf(
                'this daemon speaks control protocol %d and the client speaks %d; use the same Stewart version on both sides',
                ControlProtocol::VERSION,
                $frame->protocol,
            )));

            return null;
        }

        return $frame;
    }

    /** @throws ControlException|ConfigurationException */
    private function prepareBindAddress(): SocketAddress|string
    {
        if (!$this->address instanceof UnixControlAddress) {
            return $this->address->resolveSocketAddress($this->projectRoot);
        }

        $path = $this->address->resolveSocketPath($this->projectRoot);
        $this->socketFile->prepareForListening($path);

        return new UnixAddress($path);
    }
}
