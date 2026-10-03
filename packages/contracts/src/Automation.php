<?php

declare(strict_types=1);

namespace Stewart\Contracts;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Automation
{
    /** @param non-empty-string $id */
    public function __construct(public string $id) {}
}
