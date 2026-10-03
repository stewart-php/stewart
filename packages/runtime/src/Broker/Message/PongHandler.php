<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Message;

use Stewart\Runtime\Broker\AppMetrics;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Broker\WorkerWatchdog;
use Stewart\Runtime\Ipc\Message\Pong;
use Stewart\Runtime\Ipc\Message\WorkerMessage;

/** @implements WorkerMessageHandler<Pong> */
final readonly class PongHandler implements WorkerMessageHandler
{
    public function __construct(
        private WorkerWatchdog $watchdog,
        private AppMetrics $metrics,
    ) {}

    public function handledMessageClass(): string
    {
        return Pong::class;
    }

    /** @param Pong $message */
    public function handle(WorkerHandle $handle, WorkerMessage $message): void
    {
        $this->watchdog->recordPong($handle, $message);
        $this->metrics->recordActivityReports($handle, $message);
    }
}
