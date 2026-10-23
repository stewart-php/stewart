<?php

declare(strict_types=1);

namespace Stewart\PHPStan\Manifest;

use PHPStan\Reflection\ReflectionProvider;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Entity\TypedEntity;

// A handle is recognised through static reflection; its static methods run only once the Manifest shows that the
// tree is current.
final readonly class GeneratedHandles
{
    public function __construct(private ReflectionProvider $reflection) {}

    public function isHandle(string $class): bool
    {
        return $this->reflection->hasClass($class) && $this->reflection->getClass($class)->implementsInterface(TypedEntity::class);
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
