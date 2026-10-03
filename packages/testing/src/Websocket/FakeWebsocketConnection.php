<?php

declare(strict_types=1);

namespace Stewart\Testing\Websocket;

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Http\Client\Response;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\WebsocketCloseCode;
use Amp\Websocket\WebsocketCloseInfo;
use Amp\Websocket\WebsocketCount;
use Amp\Websocket\WebsocketMessage;
use Amp\Websocket\WebsocketTimestamp;
use Closure;
use IteratorAggregate;
use LogicException;
use SplQueue;
use Stewart\Testing\Async\Latch;
use Traversable;

/** @implements IteratorAggregate<int, WebsocketMessage> */
final class FakeWebsocketConnection implements IteratorAggregate, WebsocketConnection
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    /** @var SplQueue<string> */
    private SplQueue $inbound;

    /** @var list<DeferredFuture<null>> */
    private array $waiting = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $onSend = [];

    private bool $closed = false;

    private ?WebsocketCloseInfo $closeInfo = null;

    /** @var list<Closure(int, WebsocketCloseInfo): void> */
    private array $onClose = [];

    private ?Latch $sendRelease = null;

    private ?Latch $closeRelease = null;

    public function __construct()
    {
        $this->inbound = new SplQueue();
    }

    /** @param array<string, mixed> $frame */
    public function queueFrame(array $frame): void
    {
        $this->inbound->enqueue(self::encodeFrame($frame));
        $this->wakeReceiver();
    }

    /** @param array<string, mixed> $frame */
    public function replyWhenSent(string $type, array $frame): void
    {
        $this->onSend[$type][] = $frame;
    }

    public function holdSendsUntil(Latch $release): void
    {
        $this->sendRelease = $release;
    }

    public function holdCloseUntil(Latch $release): void
    {
        $this->closeRelease = $release;
    }

    /** @return list<array<string, mixed>> */
    public function listSentOfType(string $type): array
    {
        return array_values(array_filter($this->sent, static fn(array $command): bool => ($command['type'] ?? null) === $type));
    }

    public function receive(?Cancellation $cancellation = null): ?WebsocketMessage
    {
        while ($this->inbound->isEmpty()) {
            if ($this->closed) {
                return null;
            }

            $deferred = new DeferredFuture();
            $this->waiting[] = $deferred;
            $deferred->getFuture()->await($cancellation);
        }

        return WebsocketMessage::fromText($this->inbound->dequeue());
    }

    public function sendText(string $data): void
    {
        $this->sendRelease?->waitUntilOpen();

        if ($this->closed) {
            throw new LogicException('The fake socket is closed.');
        }

        /** @var array<string, mixed> $command */
        $command = json_decode($data, true, 512, \JSON_THROW_ON_ERROR);
        $this->sent[] = $command;

        $type = \is_string($command['type'] ?? null) ? $command['type'] : '';
        $id = $command['id'] ?? null;

        foreach ($this->onSend[$type] ?? [] as $frame) {
            $this->queueFrame(\is_int($id) ? ['id' => $id, ...$frame] : $frame);
        }

        unset($this->onSend[$type]);
    }

    public function close(int $code = WebsocketCloseCode::NORMAL_CLOSE, string $reason = ''): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $closeInfo = $this->closeInfo = new WebsocketCloseInfo($code, $reason, microtime(true), false);
        $this->wakeReceiver();
        $this->closeRelease?->waitUntilOpen();

        foreach ($this->onClose as $handler) {
            $handler($this->getId(), $closeInfo);
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function getCloseInfo(): WebsocketCloseInfo
    {
        return $this->closeInfo ??= new WebsocketCloseInfo(WebsocketCloseCode::NORMAL_CLOSE, '', microtime(true), false);
    }

    public function onClose(Closure $onClose): void
    {
        $this->onClose[] = $onClose;
    }

    public function getId(): int
    {
        return 1;
    }

    public function getLocalAddress(): SocketAddress
    {
        return new InternetAddress('127.0.0.1', 1234);
    }

    public function getRemoteAddress(): SocketAddress
    {
        return new InternetAddress('127.0.0.1', 8123);
    }

    public function getTlsInfo(): ?TlsInfo
    {
        return null;
    }

    public function isCompressionEnabled(): bool
    {
        return false;
    }

    public function sendBinary(string $data): void
    {
        throw new LogicException('Stewart never sends binary frames.');
    }

    public function streamText(ReadableStream $stream): void
    {
        throw new LogicException('Stewart never streams frames.');
    }

    public function streamBinary(ReadableStream $stream): void
    {
        throw new LogicException('Stewart never streams frames.');
    }

    public function ping(): void {}

    public function getCount(WebsocketCount $type): int
    {
        return 0;
    }

    public function getTimestamp(WebsocketTimestamp $type): float
    {
        return microtime(true);
    }

    public function getHandshakeResponse(): Response
    {
        throw new LogicException('The fake socket has no handshake response.');
    }

    public function getIterator(): Traversable
    {
        while (($message = $this->receive()) !== null) {
            yield $message;
        }
    }

    /** @param array<string, mixed> $frame */
    private static function encodeFrame(array $frame): string
    {
        return json_encode($frame, \JSON_THROW_ON_ERROR);
    }

    private function wakeReceiver(): void
    {
        $waiting = $this->waiting;
        $this->waiting = [];

        foreach ($waiting as $deferred) {
            $deferred->complete();
        }
    }
}
