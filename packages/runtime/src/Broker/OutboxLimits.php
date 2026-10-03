<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

final readonly class OutboxLimits
{
    public function __construct(
        public int $eventBuffer,
        public int $stateBatch,
    ) {}
}
