<?php

declare(strict_types=1);

namespace Stewart\Codegen\Entity;

use Stewart\Client\Registry\EntityRegistryEntry;
use Stewart\Codegen\Snapshot\Snapshot;
use Stewart\Contracts\Entity\Collection\EntityIdCollection;
use Stewart\Contracts\State\Collection\EntityStateCollection;

final readonly class EntitySelector
{
    public function selectEntities(Snapshot $snapshot, EntityInclusionRules $inclusionRules): EntitySelection
    {
        $generated = [];
        $ignored = [];

        foreach ($snapshot->states as $state) {
            if ($this->isHiddenAway($snapshot->registry->find($state->entityId)) || !$inclusionRules->allows($state->entityId)) {
                $ignored[] = $state->entityId;

                continue;
            }

            $generated[] = $state;
        }

        return new EntitySelection(EntityStateCollection::keyedByEntityId($generated), EntityIdCollection::fromIds($ignored));
    }

    private function isHiddenAway(?EntityRegistryEntry $entry): bool
    {
        return $entry !== null && ($entry->isDisabled() || $entry->isHidden());
    }
}
