<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Client;

use Amp\ByteStream\BufferedReader;
use Amp\ByteStream\BufferException;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Socket;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Config\ProjectRoot;
use Stewart\Runtime\Control\Protocol\Codec\FrameCodec;
use Stewart\Runtime\Control\Protocol\ControlProtocol;
use Stewart\Runtime\Control\Protocol\Frame\Bye;
use Stewart\Runtime\Control\Protocol\Frame\Hello;
use Stewart\Runtime\Control\Protocol\Frame\Rejected;
use Stewart\Runtime\Control\Protocol\Frame\ServerFrame;
use Stewart\Runtime\Control\Protocol\Frame\SnapshotFrame;
use Stewart\Runtime\Control\Protocol\Frame\Welcome;
use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Exception\ControlException;
use Stewart\Support\Time\Deadlines;
use Throwable;

final readonly class ControlClient
{
    private const int LINE_LIMIT = 4 * 1024 * 1024;

    private const string CLIENT_NAME = 'stewart status';

    public function __construct(
        private FrameCodec $codec,
        private ProjectRoot $projectRoot,
        private Deadlines $deadlines,
    ) {}

    /** @throws ControlException|Throwable */
    public function fetchSnapshot(ControlTarget $target, Duration $timeout): RuntimeSnapshot
    {
        $deadline = $this->deadlines->timeout($timeout);

        try {
            return $this->exchangeFrames($target, $deadline);
        } catch (CancelledException $e) {
            throw ControlException::snapshotTimedOut($timeout, $e);
        }
    }

    /** @throws ControlException|Throwable */
    private function exchangeFrames(ControlTarget $target, Cancellation $deadline): RuntimeSnapshot
    {
        $socket = Socket\connect($target->address->resolveSocketAddress($this->projectRoot), null, $deadline);

        try {
            $socket->write($this->codec->encodeFrame(new Hello($target->token, self::CLIENT_NAME)));
            $reader = new BufferedReader($socket);
            $this->expectWelcome($this->readFrame($reader, $deadline));
            $snapshot = $this->readFrame($reader, $deadline);

            if (!$snapshot instanceof SnapshotFrame) {
                throw ControlException::unexpectedFrame($snapshot::class, 'a snapshot');
            }

            $bye = $this->readFrame($reader, $deadline);

            return $bye instanceof Bye ? $snapshot->snapshot : throw ControlException::unexpectedFrame($bye::class, 'a bye');
        } finally {
            $socket->close();
        }
    }

    /** @throws ControlException|Throwable */
    private function readFrame(BufferedReader $reader, Cancellation $deadline): ServerFrame
    {
        try {
            return $this->codec->decodeServerFrame($reader->readUntil("\n", $deadline, self::LINE_LIMIT));
        } catch (BufferException) {
            throw ControlException::brokerHungUp();
        }
    }

    /** @throws ControlException */
    private function expectWelcome(object $frame): void
    {
        if ($frame instanceof Rejected) {
            throw ControlException::connectionRejected($frame->reason);
        }

        if (!$frame instanceof Welcome) {
            throw ControlException::unexpectedGreeting($frame::class);
        }

        if ($frame->protocol !== ControlProtocol::VERSION) {
            throw ControlException::protocolMismatch($frame->protocol, ControlProtocol::VERSION);
        }
    }
}
