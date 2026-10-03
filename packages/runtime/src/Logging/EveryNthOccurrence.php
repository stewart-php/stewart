<?php

declare(strict_types=1);

namespace Stewart\Runtime\Logging;

final readonly class EveryNthOccurrence
{
    private const int REPEATED_WARNING_INTERVAL = 100;

    public function __construct(private int $every) {}

    public static function forRepeatedWarnings(): self
    {
        return new self(self::REPEATED_WARNING_INTERVAL);
    }

    public function includesOccurrence(int $occurrence): bool
    {
        return $occurrence === 1 || ($occurrence > 0 && $occurrence % $this->every === 0);
    }
}
