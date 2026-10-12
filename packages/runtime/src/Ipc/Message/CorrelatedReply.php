<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Runtime\Model\CorrelationId;

interface CorrelatedReply extends BrokerMessage
{
    public CorrelationId $correlationId { get; }

    public function getAnswer(): object;
}
