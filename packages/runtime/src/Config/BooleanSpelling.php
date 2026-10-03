<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

final class BooleanSpelling
{
    private const array TRUE_SPELLINGS = ['true', '1', 'yes', 'on'];

    private const array FALSE_SPELLINGS = ['false', '0', 'no', 'off'];

    private function __construct() {}

    public static function tryParseBoolean(string $raw): ?bool
    {
        $spelling = strtolower(trim($raw));

        return match (true) {
            \in_array($spelling, self::TRUE_SPELLINGS, true) => true,
            \in_array($spelling, self::FALSE_SPELLINGS, true) => false,
            default => null,
        };
    }
}
