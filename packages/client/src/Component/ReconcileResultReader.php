<?php

declare(strict_types=1);

namespace Stewart\Client\Component;

use Stewart\Client\Exception\HaClientException;
use Stewart\Contracts\Entity\Collection\EntityIdCollection;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\IdentifierException;

final class ReconcileResultReader
{
    /**
     * @param array<array-key, mixed> $result
     * @throws HaClientException
     */
    public static function readRemovedEntityIds(array $result): EntityIdCollection
    {
        $removed = $result['removed'] ?? null;

        if (!\is_array($removed) || !array_is_list($removed)) {
            throw HaClientException::protocolViolation('a stewart/entity/reconcile result without removed');
        }

        $entityIds = [];

        foreach ($removed as $entityId) {
            if (!\is_string($entityId)) {
                throw HaClientException::protocolViolation('a stewart/entity/reconcile result with a non-string entity id');
            }

            try {
                $entityIds[] = new EntityId($entityId);
            } catch (IdentifierException $e) {
                throw HaClientException::protocolViolation('a stewart/entity/reconcile result with an invalid entity id', $e);
            }
        }

        return EntityIdCollection::fromIds($entityIds);
    }
}
