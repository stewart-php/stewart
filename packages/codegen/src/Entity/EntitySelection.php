<?php

declare(strict_types=1);

namespace Stewart\Codegen\Entity;

use Stewart\Contracts\Entity\Collection\EntityIdCollection;
use Stewart\Contracts\State\Collection\EntityStateCollection;

final readonly class EntitySelection
{
    public function __construct(
        public EntityStateCollection $generated,
        public EntityIdCollection $ignored,
    ) {}

    public function listEntityIds(): EntityIdCollection
    {
        return $this->generated->listEntityIds();
    }
}
