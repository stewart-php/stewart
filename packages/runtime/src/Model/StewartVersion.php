<?php

declare(strict_types=1);

namespace Stewart\Runtime\Model;

final readonly class StewartVersion
{
    public function __construct(public string $value) {}
}
