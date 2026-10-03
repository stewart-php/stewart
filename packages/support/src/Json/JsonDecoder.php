<?php

declare(strict_types=1);

namespace Stewart\Support\Json;

use JsonException;

/** @internal */
final class JsonDecoder
{
    private function __construct() {}

    /**
     * @param int<1, max> $depth
     * @throws JsonException
     */
    public static function decodeJson(string $json, int $depth = 512): mixed
    {
        return json_decode($json, true, $depth, \JSON_THROW_ON_ERROR);
    }
}
