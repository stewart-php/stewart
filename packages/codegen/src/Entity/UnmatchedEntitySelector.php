<?php

declare(strict_types=1);

namespace Stewart\Codegen\Entity;

use Stewart\Codegen\Model\GenerationWarning;

final readonly class UnmatchedEntitySelector implements GenerationWarning
{
    public function __construct(public string $pattern) {}

    public function describeWarning(): string
    {
        return \sprintf('The codegen include %s matches no entity.', $this->pattern);
    }
}
