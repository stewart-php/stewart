<?php

declare(strict_types=1);

use Amp\Sync\Channel;
use Stewart\Runtime\Ipc\ChannelTransport;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Stewart\Runtime\Kernel\WorkerKernel;

/** @param Channel<string, string> $channel */
return static function (Channel $channel): string {
    return new WorkerKernel()->run(new ChannelTransport($channel, IpcCodec::createForWorkerBootstrap()));
};
