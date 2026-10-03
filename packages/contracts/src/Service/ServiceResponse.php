<?php

declare(strict_types=1);

namespace Stewart\Contracts\Service;

final readonly class ServiceResponse
{
    /** @param array<string, mixed> $response */
    public function __construct(
        public string $domain,
        public string $service,
        public array $response = [],
    ) {}
}
