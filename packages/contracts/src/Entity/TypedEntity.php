<?php

declare(strict_types=1);

namespace Stewart\Contracts\Entity;

use Stewart\Contracts\Action\ActionTargetSource;

interface TypedEntity extends ActionTargetSource
{
    public EntityId $id { get; }

    public static function getDomain(): string;

    public static function isGeneratedEntityId(EntityId $id): bool;

    public function getEntity(): Entity;
}
