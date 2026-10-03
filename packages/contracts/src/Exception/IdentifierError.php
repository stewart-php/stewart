<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

enum IdentifierError: string implements ExceptionReason
{
    case EntityIdInvalid = 'entity_id_invalid';
    case AppIdInvalid = 'app_id_invalid';
    case EntityNotGenerated = 'entity_not_generated';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::EntityIdInvalid => 'Entity ID "{entityId}" is invalid; expected "<domain>.<object_id>" in lowercase letters, digits and "_".',
            self::AppIdInvalid => 'App ID "{appId}" is invalid; use lowercase letters, digits, "-" and "_", starting with a letter.',
            self::EntityNotGenerated => 'Entity ID "{entityId}" is not a generated {domain} entity; run `stewart generate` if it is new.',
        };
    }
}
