<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Client;

use Amp\ByteStream\BufferedReader;
use Amp\ByteStream\BufferException;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Socket;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Config\ProjectRoot;
use Stewart\Runtime\Control\Protocol\Codec\FrameCodec;
use Stewart\Runtime\Control\Protocol\ControlProtocol;
use Stewart\Runtime\Control\Protocol\Frame\Bye;
use Stewart\Runtime\Control\Protocol\Frame\ClientFrame;
use Stewart\Runtime\Control\Protocol\Frame\CommandResult;
use Stewart\Runtime\Control\Protocol\Frame\Hello;
use Stewart\Runtime\Control\Protocol\Frame\PauseAppRequest;
use Stewart\Runtime\Control\Protocol\Frame\Rejected;
use Stewart\Runtime\Control\Protocol\Frame\RequestFailed;
use Stewart\Runtime\Control\Protocol\Frame\ResumeAppRequest;
use Stewart\Runtime\Control\Protocol\Frame\ServerFrame;
use Stewart\Runtime\Control\Protocol\Frame\SnapshotFrame;
use Stewart\Runtime\Control\Protocol\Frame\SnapshotRequest;
use Stewart\Runtime\Control\Protocol\Frame\Welcome;
use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Exception\ControlException;
use Stewart\Support\Time\Deadlines;
use Throwable;

final readonly class ControlClient
{
    private const int LINE_LIMIT = 4 * 1024 * 1024;

    private const string CLIENT_NAME = 'stewart cli';

    public function __construct(
        private FrameCodec $codec,
        private ProjectRoot $projectRoot,
        private Deadlines $deadlines,
    ) {}

    /** @throws ControlException|Throwable */
    public function fetchSnapshot(ControlTarget $target, Duration $timeout): RuntimeSnapshot
    {
        return $this->sendRequest($target, new SnapshotRequest(), SnapshotFrame::class, $timeout)->snapshot;
    }

    /** @throws ControlException|Throwable */
    public function pauseApp(ControlTarget $target, AppId $appId, Duration $timeout): CommandResult
    {
        return $this->sendRequest($target, new PauseAppRequest($appId->value), CommandResult::class, $timeout);
    }

    /** @throws ControlException|Throwable */
    public function resumeApp(ControlTarget $target, AppId $appId, Duration $timeout): CommandResult
    {
        return $this->sendRequest($target, new ResumeAppRequest($appId->value), CommandResult::class, $timeout);
    }

    /**
     * @template T of ServerFrame
     * @param class-string<T> $responseClass
     * @return T
     * @throws ControlException|Throwable
     */
    private function sendRequest(ControlTarget $target, ClientFrame $request, string $responseClass, Duration $timeout): ServerFrame
    {
        $deadline = $this->deadlines->timeout($timeout);

        try {
            return $this->exchangeFrames($target, $request, $responseClass, $deadline);
        } catch (CancelledException $e) {
            throw ControlException::requestTimedOut($timeout, $e);
        }
    }

    /**
     * @template T of ServerFrame
     * @param class-string<T> $responseClass
     * @return T
     * @throws ControlException|Throwable
     */
    private function exchangeFrames(ControlTarget $target, ClientFrame $request, string $responseClass, Cancellation $deadline): ServerFrame
    {
        $socket = Socket\connect($target->address->resolveSocketAddress($this->projectRoot), null, $deadline);

        try {
            $socket->write($this->codec->encodeFrame(new Hello($target->token, self::CLIENT_NAME)));
            $reader = new BufferedReader($socket);
            $this->expectWelcome($this->readFrame($reader, $deadline));
            $socket->write($this->codec->encodeFrame($request));
            $response = $this->readFrame($reader, $deadline);

            if ($response instanceof RequestFailed) {
                throw ControlException::requestFailed($response->reason, $response->message);
            }

            if (!$response instanceof $responseClass) {
                throw ControlException::unexpectedFrame($response::class, $responseClass);
            }

            $bye = $this->readFrame($reader, $deadline);

            return $bye instanceof Bye ? $response : throw ControlException::unexpectedFrame($bye::class, Bye::class);
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
