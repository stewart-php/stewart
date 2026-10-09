<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Message;

use Stewart\Runtime\Broker\Exposure\ExposureProxy;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Ipc\Message\RemoveExposedEntityRequest;
use Stewart\Runtime\Ipc\Message\WorkerMessage;

/** @implements WorkerMessageHandler<RemoveExposedEntityRequest> */
final readonly class RemoveExposedEntityRequestHandler implements WorkerMessageHandler
{
    public function __construct(private ExposureProxy $exposures) {}

    public function handledMessageClass(): string
    {
        return RemoveExposedEntityRequest::class;
    }

    /** @param RemoveExposedEntityRequest $message */
    public function handle(WorkerHandle $handle, WorkerMessage $message): void
    {
        $this->exposures->forwardRemove($handle, $message);
    }
}
