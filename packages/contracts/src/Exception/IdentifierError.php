<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

enum IdentifierError: string implements ExceptionReason
{
    case EntityIdInvalid = 'entity_id_invalid';
    case AppIdInvalid = 'app_id_invalid';
    case AppIdTooLong = 'app_id_too_long';
    case EntityNotGenerated = 'entity_not_generated';
    case RegistryIdEmpty = 'registry_id_empty';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::EntityIdInvalid => 'Entity ID "{entityId}" is invalid; expected "<domain>.<object_id>" in lowercase letters, digits and "_".',
            self::AppIdInvalid => 'App ID "{appId}" is invalid; use lowercase letters, digits, "-" and "_", starting with a letter.',
            self::AppIdTooLong => 'App ID "{appId}" is longer than {limit} characters.',
            self::EntityNotGenerated => 'Entity ID "{entityId}" is not a generated {domain} entity; run `stewart generate` if it is new.',
            self::RegistryIdEmpty => '{kind} ID must not be empty.',
        };
    }
}
