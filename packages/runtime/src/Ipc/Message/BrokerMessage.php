<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

interface BrokerMessage
{
    public function getOutboxDelivery(): OutboxDelivery;
}
