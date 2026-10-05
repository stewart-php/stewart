<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broker;

final readonly class ReceivedServiceCall
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public string $domain,
        public string $service,
        public array $data,
    ) {}
}
