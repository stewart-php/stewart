<?php

declare(strict_types=1);

namespace Stewart\PHPStan\Tests\Fixtures\Generated;

use Stewart\Contracts\Action\ActionTarget;
use Stewart\Contracts\Entity\Entity;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Entity\TypedEntity;
use Stewart\Contracts\HaContext;

final readonly class LightEntity implements TypedEntity
{
    private const array GENERATED_ENTITY_IDS = ['light.hall' => true, 'light.kitchen' => true];

    private Entity $entity;

    public function __construct(HaContext $ha, public EntityId $id)
    {
        $this->entity = new Entity($ha, $id);
    }

    public static function getDomain(): string
    {
        return 'light';
    }

    public static function isGeneratedEntityId(EntityId $id): bool
    {
        return isset(self::GENERATED_ENTITY_IDS[$id->value]);
    }

    public function getEntity(): Entity
    {
        return $this->entity;
    }

    public function toActionTarget(): ActionTarget
    {
        return ActionTarget::forEntities($this->id);
    }
}
