<?php

declare(strict_types=1);

namespace Stewart\Runtime\Json;

use Stewart\Contracts\Exception\StewartException;

interface ValueConverter
{
    /** @return class-string */
    public function handledClass(): string;

    public function keySuffix(): string;

    /** @throws StewartException */
    public function encodeValue(object $value): mixed;

    /** @throws StewartException */
    public function decodeValue(mixed $value, string $path): object;
}
