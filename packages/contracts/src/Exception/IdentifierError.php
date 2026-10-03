<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

enum IdentifierError: string implements ExceptionReason
{
    case EntityIdInvalid = 'entity_id_invalid';
    case AppIdInvalid = 'app_id_invalid';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::EntityIdInvalid => 'Entity ID "{entityId}" is invalid; expected "<domain>.<object_id>" in lowercase letters, digits and "_".',
            self::AppIdInvalid => 'App ID "{appId}" is invalid; use lowercase letters, digits, "-" and "_", starting with a letter.',
        };
    }
}
