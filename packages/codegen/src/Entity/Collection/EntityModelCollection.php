<?php

declare(strict_types=1);

namespace Stewart\Codegen\Entity\Collection;

use Stewart\Codegen\Entity\EntityModel;
use Stewart\Contracts\Collection\ListCollection;

/** @extends ListCollection<EntityModel> */
final readonly class EntityModelCollection extends ListCollection
{
    /** @param iterable<EntityModel> $entities */
    public static function fromEntities(iterable $entities): self
    {
        return self::fromList($entities);
    }
}
