<?php

declare(strict_types=1);

namespace Stewart\Contracts\Wire;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class ListOf
{
    /** @param 'string'|'int'|'float'|'bool'|class-string $type */
    public function __construct(public string $type) {}
}
