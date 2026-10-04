<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\HistoryResult;
use Stewart\Runtime\Worker\PendingHistoryQueries;

/** @implements BrokerMessageHandler<HistoryResult> */
final readonly class HistoryResultHandler implements BrokerMessageHandler
{
    public function __construct(private PendingHistoryQueries $pending) {}

    public function handledMessageClass(): string
    {
        return HistoryResult::class;
    }

    /** @param HistoryResult $message */
    public function handle(BrokerMessage $message): void
    {
        $this->pending->resolve($message->correlationId, $message->states->collection);
    }
}
