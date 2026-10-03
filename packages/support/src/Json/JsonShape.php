<?php

declare(strict_types=1);

namespace Stewart\Support\Json;

use Stewart\Contracts\Exception\JsonShapeException;

/** @internal */
final class JsonShape
{
    private function __construct() {}

    /** @param array<array-key, mixed> $data */
    public static function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) ? $value : throw JsonShapeException::wrongType($key, 'a string', get_debug_type($value));
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<array<array-key, mixed>>
     */
    public static function requireObjectList(array $data, string $key): array
    {
        $objects = [];

        foreach (self::requireList($data, $key) as $item) {
            $objects[] = \is_array($item) ? $item : throw JsonShapeException::wrongType($key, 'a list of objects', get_debug_type($item));
        }

        return $objects;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<string, mixed>
     */
    public static function requireObject(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (!\is_array($value)) {
            throw JsonShapeException::wrongType($key, 'an object', get_debug_type($value));
        }

        return self::treatKeysAsStrings($value);
    }

    /**
     * @param array<array-key, mixed> $raw
     * @return array<string, mixed>
     */
    public static function treatKeysAsStrings(array $raw): array
    {
        // No copy: PHP converts numeric-string keys to int on write anyway.
        /** @var array<string, mixed> $raw */
        return $raw;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<mixed>
     */
    private static function requireList(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return \is_array($value) && array_is_list($value) ? $value : throw JsonShapeException::wrongType($key, 'a list', get_debug_type($value));
    }
}
