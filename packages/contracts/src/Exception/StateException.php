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

    public static function thresholdHysteresisNegative(string $usage, float $hysteresis): self
    {
        return self::createForReason(StateError::ThresholdHysteresisNegative, ['usage' => $usage, 'hysteresis' => is_nan($hysteresis) ? 'NAN' : $hysteresis]);
    }

    public static function thresholdNotFinite(string $usage, float $threshold): self
    {
        return self::createForReason(StateError::ThresholdNotFinite, ['usage' => $usage, 'threshold' => is_nan($threshold) ? 'NAN' : $threshold]);
    }

    public static function transitionAlreadyExtended(): self
    {
        return self::createForReason(StateError::TransitionAlreadyExtended);
    }
}
