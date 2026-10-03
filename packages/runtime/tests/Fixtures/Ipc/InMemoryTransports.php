<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Ipc;

use Amp\Sync\Channel;
use Stewart\Runtime\Ipc\ChannelTransport;
use Stewart\Runtime\Ipc\Wire\IpcCodec;

use function Amp\Sync\createChannelPair;

final class InMemoryTransports
{
    // Frames a sender may queue before it suspends, standing in for a pipe's buffer.
    private const int BUFFERED_FRAMES = 1024;

    private function __construct() {}

    public static function createPair(IpcCodec $codecs): TransportPair
    {
        /** @var array{Channel<string, string>, Channel<string, string>} $channels */
        $channels = createChannelPair(self::BUFFERED_FRAMES);

        return new TransportPair(
            broker: new ChannelTransport($channels[0], $codecs),
            worker: new ChannelTransport($channels[1], $codecs),
        );
    }
}
