<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\RequestFailed;
use Stewart\Runtime\Worker\PendingRequests;

/** @implements BrokerMessageHandler<RequestFailed> */
final readonly class RequestFailedHandler implements BrokerMessageHandler
{
    public function __construct(private PendingRequests $pending) {}

    public function handledMessageClass(): string
    {
        return RequestFailed::class;
    }

    /** @param RequestFailed $message */
    public function handle(BrokerMessage $message): void
    {
        $this->pending->rejectFromWire($message);
    }
}
