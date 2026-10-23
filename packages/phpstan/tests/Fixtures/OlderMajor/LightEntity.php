<?php

declare(strict_types=1);

namespace Stewart\PHPStan\Tests\Fixtures\OlderMajor;

use LogicException;
use Stewart\Contracts\Entity\Entity;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Entity\TypedEntity;

// Written by Stewart 1.x, with toServiceTarget() in place of toActionTarget(): loading it is a fatal error.
final readonly class LightEntity implements TypedEntity
{
    public function __construct(public EntityId $id) {}

    public static function getDomain(): string
    {
        return 'light';
    }

    public static function isGeneratedEntityId(EntityId $id): bool
    {
        return true;
    }

    public function getEntity(): Entity
    {
        throw new LogicException('Never loaded.');
    }
}
