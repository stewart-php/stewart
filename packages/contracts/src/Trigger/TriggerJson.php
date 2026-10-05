<?php

declare(strict_types=1);

namespace Stewart\Contracts\Trigger;

final class TriggerJson
{
    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    public static function sortMapKeysRecursively(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, \SORT_STRING);
        }

        foreach ($value as $key => $item) {
            if (\is_array($item)) {
                $value[$key] = self::sortMapKeysRecursively($item);
            }
        }

        return $value;
    }

    public static function findUnencodablePath(mixed $value, string $path): ?string
    {
        if (\is_float($value)) {
            return is_finite($value) ? null : $path;
        }

        if (\is_string($value)) {
            return preg_match('//u', $value) === 1 ? null : $path;
        }

        if (!\is_array($value)) {
            return $value === null || \is_scalar($value) ? null : $path;
        }

        foreach ($value as $key => $item) {
            $found = self::findUnencodablePath($item, $path . '.' . $key);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @param array<array-key, mixed> $value */
    public static function encodeCanonically(array $value): string
    {
        return json_encode(
            self::sortMapKeysRecursively($value),
            \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
    }
}
