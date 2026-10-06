<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broker;

final readonly class ReceivedEventFire
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public string $eventType,
        public array $data,
    ) {}
}
