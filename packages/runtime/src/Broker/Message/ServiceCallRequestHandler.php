<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Message;

use Stewart\Runtime\Broker\ServiceCallProxy;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Ipc\Message\ServiceCallRequest;
use Stewart\Runtime\Ipc\Message\WorkerMessage;

/** @implements WorkerMessageHandler<ServiceCallRequest> */
final readonly class ServiceCallRequestHandler implements WorkerMessageHandler
{
    public function __construct(private ServiceCallProxy $serviceCalls) {}

    public function handledMessageClass(): string
    {
        return ServiceCallRequest::class;
    }

    /** @param ServiceCallRequest $message */
    public function handle(WorkerHandle $handle, WorkerMessage $message): void
    {
        $this->serviceCalls->forward($handle, $message);
    }
}
