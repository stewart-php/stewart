<?php

declare(strict_types=1);

namespace Stewart\Testing\Store;

final readonly class StoredEntry
{
    public function __construct(
        public string $value,
        public ?int $expiresAtMilliseconds,
    ) {}
}
