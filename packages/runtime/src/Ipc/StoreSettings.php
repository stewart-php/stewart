<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc;

use SensitiveParameter;
use Stewart\Contracts\Time\Duration;

final readonly class StoreSettings
{
    public function __construct(
        #[SensitiveParameter]
        public string $dsn,
        public string $prefix,
        public Duration $timeout,
        public Duration $recoveryInterval,
    ) {}

    /** @return array{dsn: string, prefix: string, timeout: string, recovery_interval: string} */
    public function __debugInfo(): array
    {
        return [
            'dsn' => '***',
            'prefix' => $this->prefix,
            'timeout' => (string) $this->timeout,
            'recovery_interval' => (string) $this->recoveryInterval,
        ];
    }
}
