<?php

declare(strict_types=1);

namespace Stewart\Runtime\Exception;

use Stewart\Contracts\Exception\ExceptionReason;

enum AppError: string implements ExceptionReason
{
    case DuplicateId = 'duplicate_id';
    case DirectoryNotAutoloadable = 'directory_not_autoloadable';
    case NotAnApp = 'not_an_app';
    case NotInstantiable = 'not_instantiable';
    case IdInvalid = 'id_invalid';
    case InitializeTimedOut = 'initialize_timed_out';
    case Unknown = 'unknown';
    case Disabled = 'disabled';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::DuplicateId => 'Automations {firstClass} and {secondClass} both use ID "{appId}"; "-" and "_" count as the same character.',
            self::DirectoryNotAutoloadable => '{directory} is not covered by a PSR-4 autoload rule. Add it to "autoload" in composer.json and run `make install`.',
            self::NotAnApp => '{className} has #[Automation] but does not implement {appInterface}.',
            self::NotInstantiable => '{className} has #[Automation] but cannot be instantiated.',
            self::IdInvalid => '{className}: automation ID "{appId}" is invalid; use lowercase letters, digits, "-" and "_", starting with a letter.',
            self::InitializeTimedOut => '{appId} initialize() did not return within {timeout}. Raise supervision.initialize_timeout if needed.',
            self::Unknown => 'No automation with ID "{appId}".',
            self::Disabled => 'Automation "{appId}" is not loaded because apps.{appId}.enabled is false.',
        };
    }
}
