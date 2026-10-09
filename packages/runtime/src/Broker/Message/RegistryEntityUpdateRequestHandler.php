<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Message;

use Stewart\Runtime\Broker\RegistryEditProxy;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Ipc\Message\RegistryEntityUpdateRequest;
use Stewart\Runtime\Ipc\Message\WorkerMessage;

/** @implements WorkerMessageHandler<RegistryEntityUpdateRequest> */
final readonly class RegistryEntityUpdateRequestHandler implements WorkerMessageHandler
{
    public function __construct(private RegistryEditProxy $registryEdits) {}

    public function handledMessageClass(): string
    {
        return RegistryEntityUpdateRequest::class;
    }

    /** @param RegistryEntityUpdateRequest $message */
    public function handle(WorkerHandle $handle, WorkerMessage $message): void
    {
        $this->registryEdits->forward($handle, $message);
    }
}
