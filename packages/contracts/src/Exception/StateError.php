<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

enum StateError: string implements ExceptionReason
{
    case EntityNotFound = 'entity_not_found';
    case StateChangedViaEvents = 'state_changed_via_events';
    case TransitionAlreadyExtended = 'transition_already_extended';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::EntityNotFound => 'Entity "{entityId}" does not exist in Home Assistant.',
            self::StateChangedViaEvents => 'The "{eventType}" event is only delivered through watchStateChanges(), not watchEvents().',
            self::TransitionAlreadyExtended => 'from() and fromAnyState() must directly follow whenChangedTo().',
        };
    }
}
