<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Message;

use Stewart\Runtime\Broker\HistoryProxy;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Ipc\Message\HistoryRequest;
use Stewart\Runtime\Ipc\Message\WorkerMessage;

/** @implements WorkerMessageHandler<HistoryRequest> */
final readonly class HistoryRequestHandler implements WorkerMessageHandler
{
    public function __construct(private HistoryProxy $history) {}

    public function handledMessageClass(): string
    {
        return HistoryRequest::class;
    }

    /** @param HistoryRequest $message */
    public function handle(WorkerHandle $handle, WorkerMessage $message): void
    {
        $this->history->forward($handle, $message);
    }
}
