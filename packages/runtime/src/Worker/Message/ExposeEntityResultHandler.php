<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\ExposeEntityResult;
use Stewart\Runtime\Worker\PendingRequests;

/** @implements BrokerMessageHandler<ExposeEntityResult> */
final readonly class ExposeEntityResultHandler implements BrokerMessageHandler
{
    public function __construct(private PendingRequests $pending) {}

    public function handledMessageClass(): string
    {
        return ExposeEntityResult::class;
    }

    /** @param ExposeEntityResult $message */
    public function handle(BrokerMessage $message): void
    {
        $this->pending->resolve($message->correlationId, $message);
    }
}
