<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Contracts\Time\Duration;

final readonly class RestartDecision
{
    private function __construct(public ?Duration $delay) {}

    public static function restartAfter(Duration $delay): self
    {
        return new self($delay);
    }

    public static function quarantine(): self
    {
        return new self(null);
    }
}
