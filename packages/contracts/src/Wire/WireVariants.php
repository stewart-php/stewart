<?php

declare(strict_types=1);

namespace Stewart\Contracts\Wire;

use Attribute;

/** @internal */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class WireVariants
{
    /** @param array<string, class-string> $variants */
    public function __construct(
        public string $key,
        public array $variants,
    ) {}

    public function findVariantName(object $value): ?string
    {
        $name = array_search($value::class, $this->variants, true);

        return \is_string($name) ? $name : null;
    }
}
