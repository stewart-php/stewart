<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\ExposureAcknowledged;
use Stewart\Runtime\Worker\PendingRequests;

/** @implements BrokerMessageHandler<ExposureAcknowledged> */
final readonly class ExposureAcknowledgedHandler implements BrokerMessageHandler
{
    public function __construct(private PendingRequests $pending) {}

    public function handledMessageClass(): string
    {
        return ExposureAcknowledged::class;
    }

    /** @param ExposureAcknowledged $message */
    public function handle(BrokerMessage $message): void
    {
        $this->pending->resolve($message->correlationId, $message);
    }
}
