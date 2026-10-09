<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Message;

use Stewart\Runtime\Broker\Exposure\ExposureProxy;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Ipc\Message\ExposeEntityRequest;
use Stewart\Runtime\Ipc\Message\WorkerMessage;

/** @implements WorkerMessageHandler<ExposeEntityRequest> */
final readonly class ExposeEntityRequestHandler implements WorkerMessageHandler
{
    public function __construct(private ExposureProxy $exposures) {}

    public function handledMessageClass(): string
    {
        return ExposeEntityRequest::class;
    }

    /** @param ExposeEntityRequest $message */
    public function handle(WorkerHandle $handle, WorkerMessage $message): void
    {
        $this->exposures->forwardExpose($handle, $message);
    }
}
