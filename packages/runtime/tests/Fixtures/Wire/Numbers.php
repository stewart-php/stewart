<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Wire;

use Stewart\Runtime\Json\JsonFragment;
use Stewart\Runtime\Json\WireMapper;

final readonly class Numbers implements JsonFragment
{
    /** @param list<int> $values */
    public function __construct(public array $values) {}

    public static function fromDecodedValue(mixed $value, string $path, WireMapper $mapper): static
    {
        \assert(\is_array($value));

        return new static(array_values(array_filter($value, \is_int(...))));
    }

    public function encodeToJson(WireMapper $mapper): string
    {
        return '[' . implode(',', $this->values) . ']';
    }
}
