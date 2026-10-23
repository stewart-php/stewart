<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Action\ActionResponse;
use Stewart\Runtime\Model\CorrelationId;

#[IpcMessage(tag: 'action_call_result')]
final readonly class ActionCallResult implements CorrelatedReply
{
    public function __construct(
        public CorrelationId $correlationId,
        public ActionResponse $result,
    ) {}

    public function getAnswer(): ActionResponse
    {
        return $this->result;
    }

    public function getOutboxDelivery(): OutboxDelivery
    {
        return OutboxDelivery::Plain;
    }
}
