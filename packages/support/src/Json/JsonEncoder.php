<?php

declare(strict_types=1);

namespace Stewart\Support\Json;

use JsonException;

/** @internal */
final class JsonEncoder
{
    private const int ENCODE_FLAGS = \JSON_THROW_ON_ERROR
        | \JSON_PRESERVE_ZERO_FRACTION
        | \JSON_UNESCAPED_UNICODE
        | \JSON_UNESCAPED_SLASHES
        | \JSON_INVALID_UTF8_SUBSTITUTE;

    private function __construct() {}

    /**
     * @param int<1, max> $depth
     * @throws JsonException
     */
    public static function encodeToJson(mixed $value, int $depth = 512): string
    {
        return json_encode($value, self::ENCODE_FLAGS, $depth);
    }
}
