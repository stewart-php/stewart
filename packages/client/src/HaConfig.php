<?php

declare(strict_types=1);

namespace Stewart\Client;

use DateTimeZone;
use Exception;

final readonly class HaConfig
{
    public function __construct(
        public ?string $timeZoneName = null,
        public ?DateTimeZone $timeZone = null,
        public ?HaCoreState $coreState = null,
    ) {}

    /** @param array<array-key, mixed> $result */
    public static function fromGetConfigResult(array $result): self
    {
        $timeZoneName = $result['time_zone'] ?? null;
        $coreState = \is_string($result['state'] ?? null) ? HaCoreState::tryFrom($result['state']) : null;

        if (!\is_string($timeZoneName) || $timeZoneName === '') {
            return new self(coreState: $coreState);
        }

        return new self($timeZoneName, self::parseTimeZone($timeZoneName), $coreState);
    }

    private static function parseTimeZone(string $name): ?DateTimeZone
    {
        try {
            return new DateTimeZone($name);
        } catch (Exception) {
            return null;
        }
    }
}
