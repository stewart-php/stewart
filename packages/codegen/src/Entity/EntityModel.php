<?php

declare(strict_types=1);

namespace Stewart\Codegen\Entity;

use Stewart\Contracts\Entity\EntityId;

final readonly class EntityModel
{
    public function __construct(
        public EntityId $entityId,
        public string $accessor,
        public string $friendlyName,
    ) {}
}
