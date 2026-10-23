<?php

declare(strict_types=1);

namespace Stewart\PHPStan\Manifest;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Entity\TypedEntity;

// Generated handles are read as plain PHP through the project's autoloader, like the Manifest.
final readonly class GeneratedHandles
{
    public function isHandle(string $class): bool
    {
        return is_a($class, TypedEntity::class, true);
    }

    public function findDomain(string $class): ?string
    {
        return is_a($class, TypedEntity::class, true) ? $class::getDomain() : null;
    }

    public function isGeneratedEntityId(string $class, string $entityId): bool
    {
        return is_a($class, TypedEntity::class, true) && $class::isGeneratedEntityId(new EntityId($entityId));
    }
}
