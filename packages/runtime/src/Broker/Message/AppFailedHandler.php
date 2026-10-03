<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Message;

use Psr\Log\LoggerInterface;
use Stewart\Runtime\Broker\AppMetrics;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Ipc\Message\AppFailed;
use Stewart\Runtime\Ipc\Message\WorkerMessage;
use Stewart\Runtime\Model\ExceptionDetails;

/** @implements WorkerMessageHandler<AppFailed> */
final readonly class AppFailedHandler implements WorkerMessageHandler
{
    public function __construct(
        private LoggerInterface $logger,
        private AppMetrics $metrics,
    ) {}

    public function handledMessageClass(): string
    {
        return AppFailed::class;
    }

    /** @param AppFailed $message */
    public function handle(WorkerHandle $handle, WorkerMessage $message): void
    {
        $this->logger->error('App failed', [
            'app' => $message->scope->wireValue(),
            'phase' => $message->phase->value,
            'origin' => $message->origin,
            'occurrence' => $message->occurrence,
            'worker' => $handle->id->value,
            'exception' => $message->class,
            'error' => $message->message,
            'trace' => $message->trace,
            ExceptionDetails::CONTEXT_KEY => $message->details,
        ]);

        $this->metrics->recordLastFailure($handle->id, $message);
    }
}
