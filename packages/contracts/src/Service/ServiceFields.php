<?php

declare(strict_types=1);

namespace Stewart\Contracts\Service;

final readonly class ServiceFields
{
    /** @param array<string, mixed> $fields */
    private function __construct(public array $fields) {}

    /** @param array<string, mixed> $fields */
    public static function fromFieldsDroppingNulls(array $fields): self
    {
        return new self(array_filter($fields, static fn(mixed $value): bool => $value !== null));
    }
}
