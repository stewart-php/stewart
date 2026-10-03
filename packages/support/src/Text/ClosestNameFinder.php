<?php

declare(strict_types=1);

namespace Stewart\Support\Text;

/** @internal */
final readonly class ClosestNameFinder
{
    private const int MINIMUM_ALLOWED_DISTANCE = 2;

    private const int CHARACTERS_PER_ALLOWED_EDIT = 3;

    /** @param iterable<string> $candidates */
    public function findClosestName(string $name, iterable $candidates): ?string
    {
        $closest = null;
        $shortestDistance = max(self::MINIMUM_ALLOWED_DISTANCE, intdiv(\strlen($name), self::CHARACTERS_PER_ALLOWED_EDIT)) + 1;

        foreach ($candidates as $candidate) {
            $distance = levenshtein($name, $candidate);

            if ($distance < $shortestDistance) {
                $closest = $candidate;
                $shortestDistance = $distance;
            }
        }

        return $closest;
    }
}
