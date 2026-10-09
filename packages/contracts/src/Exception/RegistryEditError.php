<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

enum RegistryEditError: string implements ExceptionReason
{
    case NotFound = 'registry_edit_not_found';
    case NothingToUpdate = 'registry_edit_nothing_to_update';
    case Rejected = 'registry_edit_rejected';
    case Unreachable = 'registry_edit_unreachable';
    case TimedOut = 'registry_edit_timed_out';
    case Overloaded = 'registry_edit_overloaded';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::NotFound => 'Entity {entityId} is not in the Home Assistant entity registry.',
            self::NothingToUpdate => 'The registry update for {entityId} changes nothing.',
            self::Rejected => 'Home Assistant rejected the registry update for {entityId}: {detail}',
            self::Unreachable => 'The registry update for {entityId} could not reach Home Assistant: {detail}',
            self::TimedOut => 'The registry update for {entityId} timed out: {detail}',
            self::Overloaded => 'The registry update for {entityId} was refused: {detail}',
        };
    }
}
