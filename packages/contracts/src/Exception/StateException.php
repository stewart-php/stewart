<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

use Stewart\Contracts\Entity\EntityId;

/** @extends StewartException<StateError> */
final class StateException extends StewartException
{
    public static function entityNotFound(EntityId $entityId): self
    {
        return self::createForReason(StateError::EntityNotFound, ['entityId' => $entityId->value]);
    }

    public static function stateChangedViaEvents(string $eventType): self
    {
        return self::createForReason(StateError::StateChangedViaEvents, ['eventType' => $eventType]);
    }
}
