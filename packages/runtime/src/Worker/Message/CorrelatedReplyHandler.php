<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\CorrelatedReply;
use Stewart\Runtime\Worker\PendingRequests;

/** @implements BrokerMessageHandler<CorrelatedReply> */
final readonly class CorrelatedReplyHandler implements BrokerMessageHandler
{
    public function __construct(private PendingRequests $pending) {}

    public function handledMessageClass(): string
    {
        return CorrelatedReply::class;
    }

    /** @param CorrelatedReply $message */
    public function handle(BrokerMessage $message): void
    {
        $this->pending->resolve($message->correlationId, $message->getAnswer());
    }
}
