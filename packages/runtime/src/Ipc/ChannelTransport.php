<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc;

use Amp\Sync\Channel;
use Amp\Sync\ChannelException;
use Stewart\Runtime\Exception\TransportError;
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
            throw TransportException::createForReason(TransportError::Closed);
        }

        $frame = $this->codecs->encodeMessage($message);

        try {
            $this->channel->send($frame);
        } catch (ChannelException $e) {
            throw TransportException::createForReason(TransportError::SendFailed, $e);
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
            throw TransportException::createForReason(TransportError::PeerDisconnected, $e);
        } catch (Throwable $e) {
            throw TransportException::createForReason(TransportError::ReceiveFailed, $e);
        }

        if ($frame === null) {
            return null;
        }

        if (!\is_string($frame)) {
            throw TransportException::createForReason(TransportError::UnexpectedMessage, actualType: get_debug_type($frame));
        }

        return $this->codecs->decodeMessage($frame);
    }

    public function close(): void
    {
        $this->closed = true;
        $this->channel->close();
    }
}
