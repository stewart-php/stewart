<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc;

use Amp\Sync\Channel;
use Amp\Sync\ChannelException;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Throwable;

final class ChannelTransport implements Transport
{
    private bool $closed = false;

    /** @param Channel<string, string> $channel */
    public function __construct(
        private readonly Channel $channel,
        private readonly IpcCodec $codecs,
    ) {}

    public function send(object $message): void
    {
        if ($this->closed) {
            throw TransportException::closed();
        }

        $frame = $this->codecs->encodeMessage($message);

        try {
            $this->channel->send($frame);
        } catch (ChannelException $e) {
            throw TransportException::sendFailed($e);
        }
    }

    public function receive(): ?object
    {
        if ($this->closed) {
            return null;
        }

        try {
            $frame = $this->channel->receive();
        } catch (ChannelException $e) {
            throw TransportException::peerDisconnected($e);
        } catch (Throwable $e) {
            throw TransportException::receiveFailed($e);
        }

        if ($frame === null) {
            return null;
        }

        if (!\is_string($frame)) {
            throw TransportException::unexpectedMessage(get_debug_type($frame));
        }

        return $this->codecs->decodeMessage($frame);
    }

    public function close(): void
    {
        $this->closed = true;
        $this->channel->close();
    }
}
