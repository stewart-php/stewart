<?php

declare(strict_types=1);

namespace Stewart\Codegen\Entity;

use Stewart\Codegen\Model\GenerationWarning;

final readonly class UnmatchedRename implements GenerationWarning
{
    public function __construct(
        public string $entityId,
        public string $name,
    ) {}

    public function describeWarning(): string
    {
        return \sprintf('The rename of %s to %s matches no generated entity.', $this->entityId, $this->name);
    }
}
