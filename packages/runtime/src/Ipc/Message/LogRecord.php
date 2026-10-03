<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Runtime\Model\ExceptionDetails;
use Stewart\Runtime\Model\LogLevel;
use Stewart\Runtime\Model\ResourceScope;

#[IpcMessage(tag: 'log_record')]
final readonly class LogRecord implements WorkerMessage
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public ResourceScope $scope,
        public LogLevel $level,
        public string $message,
        public array $context,
        public ?ExceptionDetails $exception,
    ) {}
}
