<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc;

use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Model\LogLevel;

final readonly class WorkerSettings
{
    public function __construct(
        public LogLevel $logLevel,
        public Duration $callTimeout,
        public Duration $shutdownGrace,
        public int $subscriptionBuffer,
        public ?Duration $initializeTimeout,
        public ?string $servicesFile,
        public string $generatedNamespace,
        public bool $mqttEnabled,
    ) {}
}
