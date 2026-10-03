<?php

declare(strict_types=1);

namespace Stewart\Client\Connection;

use SensitiveParameter;
use Stewart\Contracts\Time\Duration;

final readonly class ConnectionConfig
{
    public function __construct(
        public HomeAssistantUrl $url,
        #[SensitiveParameter]
        public string $token,
        public Duration $connectTimeout,
        public Duration $commandTimeout,
        public ?Duration $heartbeatInterval,
        public int $messageSizeLimit = 64 * 1024 * 1024,
        public int $frameSizeLimit = 64 * 1024 * 1024,
        public int $heartbeatMissedLimit = 3,
        public int $eventBufferLimit = 10_000,
    ) {}
}
