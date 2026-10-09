<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Message;

use Stewart\Runtime\Broker\Exposure\ExposureProxy;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Ipc\Message\UpdateExposedEntityRequest;
use Stewart\Runtime\Ipc\Message\WorkerMessage;

/** @implements WorkerMessageHandler<UpdateExposedEntityRequest> */
final readonly class UpdateExposedEntityRequestHandler implements WorkerMessageHandler
{
    public function __construct(private ExposureProxy $exposures) {}

    public function handledMessageClass(): string
    {
        return UpdateExposedEntityRequest::class;
    }

    /** @param UpdateExposedEntityRequest $message */
    public function handle(WorkerHandle $handle, WorkerMessage $message): void
    {
        $this->exposures->forwardUpdate($handle, $message);
    }
}
