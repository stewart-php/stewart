<?php

declare(strict_types=1);

namespace Stewart\Contracts\Registry;

final readonly class RegistryNames
{
    public static function containsName(string $candidate, string ...$names): bool
    {
        $candidate = trim($candidate);

        return array_any($names, static fn(string $name): bool => strcasecmp($name, $candidate) === 0);
    }
}
