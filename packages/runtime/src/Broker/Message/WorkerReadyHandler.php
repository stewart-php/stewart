<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Message;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\App\AppId;
use Stewart\Runtime\Broker\Exposure\ExposureReconciler;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Broker\WorkerPool;
use Stewart\Runtime\Ipc\Message\WorkerMessage;
use Stewart\Runtime\Ipc\Message\WorkerReady;

/** @implements WorkerMessageHandler<WorkerReady> */
final readonly class WorkerReadyHandler implements WorkerMessageHandler
{
    public function __construct(
        private WorkerPool $pool,
        private ExposureReconciler $reconciler,
        private LoggerInterface $logger,
    ) {}

    public function handledMessageClass(): string
    {
        return WorkerReady::class;
    }

    /** @param WorkerReady $message */
    public function handle(WorkerHandle $handle, WorkerMessage $message): void
    {
        $this->pool->markReady($handle);
        $this->reconciler->recordWorkerReady(
            $handle->id,
            $message->appIds->collection->filter(static fn(AppId $appId): bool => !$message->failedAppIds->collection->containsId($appId)),
        );

        $this->logger->info('Worker ready', [
            'worker' => $handle->id->value,
            'pid' => $handle->getPid(),
            'apps' => $message->appIds->collection->toStrings(),
            'failed' => $message->failedAppIds->collection->toStrings(),
            'memory_mb' => round($message->memoryBytes / 1048576, 1),
        ]);
    }
}
