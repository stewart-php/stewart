<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Message;

use Stewart\Runtime\Broker\Exposure\ExposureProxy;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Ipc\Message\ReconfigureExposedEntityRequest;
use Stewart\Runtime\Ipc\Message\WorkerMessage;

/** @implements WorkerMessageHandler<ReconfigureExposedEntityRequest> */
final readonly class ReconfigureExposedEntityRequestHandler implements WorkerMessageHandler
{
    public function __construct(private ExposureProxy $exposures) {}

    public function handledMessageClass(): string
    {
        return ReconfigureExposedEntityRequest::class;
    }

    /** @param ReconfigureExposedEntityRequest $message */
    public function handle(WorkerHandle $handle, WorkerMessage $message): void
    {
        $this->exposures->forwardReconfigure($handle, $message);
    }
}
