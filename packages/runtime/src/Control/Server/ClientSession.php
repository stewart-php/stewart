<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Server;

use Amp\ByteStream\BufferedReader;
use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\Socket\Socket;
use Stewart\Runtime\Control\Protocol\Codec\FrameCodec;
use Stewart\Runtime\Control\Protocol\Frame\ServerFrame;
use Stewart\Runtime\Exception\ControlException;
use Throwable;

final readonly class ClientSession
{
    private BufferedReader $reader;

    private string $deadlineSubscription;

    // Closing on the deadline aborts a pending write, which takes no cancellation.
    public function __construct(
        public int $id,
        private Socket $socket,
        private FrameCodec $codec,
        private Cancellation $deadline,
    ) {
        $this->reader = new BufferedReader($socket);
        $this->deadlineSubscription = $deadline->subscribe($socket->close(...));
    }

    public function describePeer(): string
    {
        return $this->socket->getRemoteAddress()->toString();
    }

    public function hasExpired(): bool
    {
        return $this->deadline->isRequested();
    }

    /**
     * @param positive-int $limit
     * @throws Throwable
     */
    public function readLine(int $limit): string
    {
        return rtrim($this->reader->readUntil("\n", $this->deadline, $limit), "\r\n");
    }

    /** @throws ControlException|StreamException */
    public function send(ServerFrame $frame): void
    {
        $this->socket->write($this->codec->encodeFrame($frame));
    }

    public function close(): void
    {
        $this->deadline->unsubscribe($this->deadlineSubscription);
        $this->socket->close();
    }
}
