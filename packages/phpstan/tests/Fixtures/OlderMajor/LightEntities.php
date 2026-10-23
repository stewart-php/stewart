<?php

declare(strict_types=1);

namespace Stewart\PHPStan\Tests\Fixtures\OlderMajor;

use Stewart\Contracts\Entity\EntityId;

final readonly class LightEntities
{
    public function getEntity(string|EntityId $id): LightEntity
    {
        return new LightEntity(EntityId::fromStringOrId($id));
    }
}
