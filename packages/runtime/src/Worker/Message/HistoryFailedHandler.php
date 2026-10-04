<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\HistoryFailed;
use Stewart\Runtime\Worker\PendingHistoryQueries;

/** @implements BrokerMessageHandler<HistoryFailed> */
final readonly class HistoryFailedHandler implements BrokerMessageHandler
{
    public function __construct(private PendingHistoryQueries $pending) {}

    public function handledMessageClass(): string
    {
        return HistoryFailed::class;
    }

    /** @param HistoryFailed $message */
    public function handle(BrokerMessage $message): void
    {
        $this->pending->reject($message);
    }
}
