<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Wire;

use Stewart\Runtime\Json\ValueConverter;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Support\Exception\JsonShapeException;

final readonly class WorkerIdConverter implements ValueConverter
{
    private const string EXPECTED = 'a worker index';

    public function handledClass(): string
    {
        return WorkerId::class;
    }

    public function keySuffix(): string
    {
        return '';
    }

    public function encodeValue(object $value): int
    {
        \assert($value instanceof WorkerId);

        return $value->value;
    }

    public function decodeValue(mixed $value, string $path): WorkerId
    {
        if (!\is_int($value)) {
            throw JsonShapeException::wrongType($path, self::EXPECTED, get_debug_type($value));
        }

        return WorkerId::fromInt($value);
    }
}
