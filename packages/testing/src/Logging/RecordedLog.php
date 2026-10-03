<?php

declare(strict_types=1);

namespace Stewart\Testing\Logging;

final readonly class RecordedLog
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public string $level,
        public string $message,
        public array $context,
    ) {}
}
