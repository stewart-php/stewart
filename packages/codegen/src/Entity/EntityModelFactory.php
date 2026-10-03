<?php

declare(strict_types=1);

namespace Stewart\Codegen\Entity;

use Stewart\Codegen\Entity\Collection\EntityModelCollection;
use Stewart\Codegen\Exception\CodegenException;
use Stewart\Codegen\Model\Collection\GenerationWarningCollection;
use Stewart\Codegen\Php\Identifier;
use Stewart\Codegen\Php\IdentifierAllocator;
use Stewart\Codegen\Php\MemberReservations;
use Stewart\Codegen\Php\MemberScope;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\EntityState;

final readonly class EntityModelFactory
{
    public function __construct(private MemberReservations $memberReservations) {}

    /**
     * @param array<string, string> $renames
     * @throws CodegenException
     */
    public function buildEntityModels(EntityStateCollection $states, array $renames): EntityModelCollection
    {
        $names = $this->memberReservations->createAllocatorFor(MemberScope::DomainCollection);
        $renamedByName = $this->claimRenames($states, $renames, $names);
        $entities = [];

        foreach ($states as $state) {
            $accessor = $renames[$state->entityId->value] ?? $this->claimNaturalName($state, $renamedByName, $names);
            $entities[] = new EntityModel($state->entityId, $accessor, $state->getFriendlyName());
        }

        return EntityModelCollection::fromEntities($entities);
    }

    /** @param array<string, string> $renames */
    public function listUnmatchedRenames(EntityStateCollection $generated, array $renames): GenerationWarningCollection
    {
        $unmatched = [];

        foreach ($renames as $entityId => $name) {
            $id = EntityId::tryFromString((string) $entityId);

            if ($id === null || $generated->find($id) === null) {
                $unmatched[] = new UnmatchedRename((string) $entityId, $name);
            }
        }

        return GenerationWarningCollection::fromWarnings($unmatched);
    }

    /**
     * @param array<string, string> $renames
     * @return array<string, EntityId>
     * @throws CodegenException
     */
    private function claimRenames(EntityStateCollection $states, array $renames, IdentifierAllocator $names): array
    {
        $renamedByName = [];

        foreach ($states as $state) {
            $name = $renames[$state->entityId->value] ?? null;

            if ($name === null) {
                continue;
            }

            if ($names->isReserved($name)) {
                throw CodegenException::renameReserved($state->entityId, $name);
            }

            $holder = $renamedByName[strtolower($name)] ?? null;

            if ($holder !== null) {
                throw CodegenException::renameCollision($state->entityId, $name, $holder);
            }

            $names->claimExactly($name);
            $renamedByName[strtolower($name)] = $state->entityId;
        }

        return $renamedByName;
    }

    /**
     * @param array<string, EntityId> $renamedByName
     * @throws CodegenException
     */
    private function claimNaturalName(EntityState $state, array $renamedByName, IdentifierAllocator $names): string
    {
        $natural = Identifier::convertToCamelCase($state->getObjectId());
        $renamed = $renamedByName[strtolower($natural)] ?? null;

        if ($renamed !== null) {
            throw CodegenException::renameCollision($renamed, $natural, $state->entityId);
        }

        return $names->claim($natural);
    }
}
