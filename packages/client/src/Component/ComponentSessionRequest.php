<?php

declare(strict_types=1);

namespace Stewart\Client\Component;

use Stewart\Contracts\Time\Duration;

final readonly class ComponentSessionRequest
{
    public function __construct(
        public ComponentInstance $instance,
        public string $stewartVersion,
        public Duration $commandTimeout,
    ) {}
}
