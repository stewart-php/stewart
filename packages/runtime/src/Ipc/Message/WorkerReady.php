<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Runtime\Ipc\Wire\AppIdsFragment;

#[IpcMessage(tag: 'worker_ready')]
final readonly class WorkerReady implements WorkerMessage
{
    public function __construct(
        public AppIdsFragment $appIds,
        public AppIdsFragment $failedAppIds,
        public int $memoryBytes,
    ) {}
}
