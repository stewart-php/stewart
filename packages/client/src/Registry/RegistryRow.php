<?php

declare(strict_types=1);

namespace Stewart\Client\Registry;

use Stewart\Contracts\Registry\Collection\LabelIdCollection;
use Stewart\Contracts\Registry\LabelId;

final readonly class RegistryRow
{
    /** @param array<array-key, mixed> $raw */
    public static function readString(array $raw, string $key): ?string
    {
        $value = $raw[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $raw
     * @return list<string>
     */
    public static function readStrings(array $raw, string $key): array
    {
        $values = $raw[$key] ?? null;

        if (!\is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, static fn(mixed $value): bool => \is_string($value) && $value !== ''));
    }

    /** @param array<array-key, mixed> $raw */
    public static function readLabelIds(array $raw): LabelIdCollection
    {
        return LabelIdCollection::fromIds(array_filter(array_map(LabelId::tryFromString(...), self::readStrings($raw, 'labels'))));
    }
}
