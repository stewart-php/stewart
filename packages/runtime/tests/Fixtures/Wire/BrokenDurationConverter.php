<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Wire;

use RuntimeException;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Json\DurationConverter;
use Stewart\Runtime\Json\ValueConverter;

final readonly class BrokenDurationConverter implements ValueConverter
{
    public function handledClass(): string
    {
        return Duration::class;
    }

    public function keySuffix(): string
    {
        return '_us';
    }

    public function encodeValue(object $value): int
    {
        return new DurationConverter()->encodeValue($value);
    }

    public function decodeValue(mixed $value, string $path): Duration
    {
        throw new RuntimeException('converter bug');
    }
}
