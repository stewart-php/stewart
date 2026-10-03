<?php

declare(strict_types=1);

namespace Stewart\Runtime\Json;

use JsonException;
use Stewart\Contracts\Exception\StewartException;

interface JsonFragment
{
    /** @throws JsonException|StewartException */
    public function encodeToJson(WireMapper $mapper): string;

    /** @throws StewartException */
    public static function fromDecodedValue(mixed $value, string $path, WireMapper $mapper): static;
}
