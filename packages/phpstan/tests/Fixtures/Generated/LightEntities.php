<?php

declare(strict_types=1);

namespace Stewart\PHPStan\Tests\Fixtures\Generated;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\HaContext;

final readonly class LightEntities
{
    public function __construct(private HaContext $ha) {}

    public function getEntity(string|EntityId $id): LightEntity
    {
        return new LightEntity($this->ha, EntityId::fromStringOrId($id));
    }
}
