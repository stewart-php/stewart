<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\ExposureFailed;
use Stewart\Runtime\Worker\PendingRequests;

/** @implements BrokerMessageHandler<ExposureFailed> */
final readonly class ExposureFailedHandler implements BrokerMessageHandler
{
    public function __construct(private PendingRequests $pending) {}

    public function handledMessageClass(): string
    {
        return ExposureFailed::class;
    }

    /** @param ExposureFailed $message */
    public function handle(BrokerMessage $message): void
    {
        $this->pending->reject($message->correlationId, $message->toException());
    }
}
