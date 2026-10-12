<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\History\Collection\HistoricalStateCollection;
use Stewart\Runtime\Ipc\Wire\HistoricalStatesFragment;
use Stewart\Runtime\Model\CorrelationId;

#[IpcMessage(tag: 'history_result')]
final readonly class HistoryResult implements CorrelatedReply
{
    public function __construct(
        public CorrelationId $correlationId,
        public HistoricalStatesFragment $states,
    ) {}

    public function getAnswer(): HistoricalStateCollection
    {
        return $this->states->collection;
    }

    public function getOutboxDelivery(): OutboxDelivery
    {
        return OutboxDelivery::Plain;
    }
}
