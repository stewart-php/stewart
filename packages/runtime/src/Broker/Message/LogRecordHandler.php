<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Message;

use Psr\Log\LoggerInterface;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Ipc\Message\LogRecord;
use Stewart\Runtime\Ipc\Message\WorkerMessage;
use Stewart\Runtime\Model\ExceptionDetails;

/** @implements WorkerMessageHandler<LogRecord> */
final readonly class LogRecordHandler implements WorkerMessageHandler
{
    public function __construct(private LoggerInterface $logger) {}

    public function handledMessageClass(): string
    {
        return LogRecord::class;
    }

    /** @param LogRecord $message */
    public function handle(WorkerHandle $handle, WorkerMessage $message): void
    {
        $this->logger->log($message->level->value, $message->message, [
            ...$message->context,
            'app' => $message->scope->wireValue(),
            'worker' => $handle->id->value,
            ExceptionDetails::CONTEXT_KEY => $message->exception,
        ]);
    }
}
