<?php

declare(strict_types=1);

namespace Stewart\Runtime\Exception;

use Stewart\Contracts\Exception\ExceptionReason;

enum ConfigurationError: string implements ExceptionReason
{
    case SchemaViolation = 'schema_violation';
    case ConfigFileMissing = 'config_file_missing';
    case ConfigFileUnparsable = 'config_file_unparsable';
    case ConfigFileNotAMap = 'config_file_not_a_map';
    case ValueInvalid = 'value_invalid';
    case KeyInvalid = 'key_invalid';
    case KeyParseFailed = 'key_parse_failed';
    case DurationTooShort = 'duration_too_short';
    case MaxDelayBelowInitialDelay = 'max_delay_below_initial_delay';
    case RestartWindowTooShort = 'restart_window_too_short';
    case HomeAssistantMissing = 'home_assistant_missing';
    case PlaceholderUnset = 'placeholder_unset';
    case ControlTokenMissing = 'control_token_missing';
    case WorkerIndexOutOfRange = 'worker_index_out_of_range';
    case AppNameMismatch = 'app_name_mismatch';
    case AppUnknown = 'app_unknown';
    case AppSelectionUnknown = 'app_selection_unknown';
    case EnvironmentVariableUnknown = 'environment_variable_unknown';
    case EnvironmentVariableTooDeep = 'environment_variable_too_deep';
    case EnvironmentVariableNamesSection = 'environment_variable_names_section';
    case EnvironmentValueInvalid = 'environment_value_invalid';
    case EnvironmentValueUnparsable = 'environment_value_unparsable';
    case EnvironmentVariableConflict = 'environment_variable_conflict';
    case EnvironmentSecretFileUnreadable = 'environment_secret_file_unreadable';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::SchemaViolation => '{cause}',
            self::ConfigFileMissing => 'Config file {path} not found.',
            self::ConfigFileUnparsable => 'Could not parse {path}: {cause}',
            self::ConfigFileNotAMap => '{path} holds {actualType}; expected a map of settings.',
            self::ValueInvalid => '"{value}" is not {expected}.',
            self::KeyInvalid => '{path} must be {expected}.',
            self::KeyParseFailed => '{path} is invalid: {cause}',
            self::DurationTooShort => '{path} is {value}; expected at least {minimum}.',
            self::MaxDelayBelowInitialDelay => '{path} is {value}; expected at least {initialDelayPath} ({initialDelay}).',
            self::RestartWindowTooShort => 'supervision.restart_window is {window}, but the backoff for restart_attempts ({attempts}) adds up to {backoffTotal}, so a crash-looping worker is never quarantined.',
            self::HomeAssistantMissing => 'home_assistant is not configured. Set STEWART_HOME_ASSISTANT__URL and STEWART_HOME_ASSISTANT__TOKEN.',
            self::PlaceholderUnset => '{setting} refers to ${{variable}}, which is not set. Set it or add a default: ${{variable}:-value}.',
            self::ControlTokenMissing => 'control.token is required while control.listen is set; set STEWART_CONTROL__TOKEN or set control.listen to "off".',
            self::WorkerIndexOutOfRange => 'App "{appId}" is pinned to worker {worker}, but the highest worker index is {highestIndex}.',
            self::AppNameMismatch => 'stewart.yaml configures app "{appId}", but the automation ID is "{automationId}".',
            self::AppUnknown => 'stewart.yaml configures app "{appId}", but no automation has that ID.',
            self::AppSelectionUnknown => '--only names app "{appId}", but no automation has that ID.',
            self::EnvironmentVariableUnknown => '{variable} is not a Stewart setting; run `stewart config:reference` for all options.',
            self::EnvironmentVariableTooDeep => '{variable} is deeper than the configuration; {valuePath} is a value, not a section.',
            self::EnvironmentVariableNamesSection => '{variable} names a section; set the keys under it, such as {variable}{separator}KEY.',
            self::EnvironmentValueInvalid => '{variable} is "{raw}"; expected {expected}.',
            self::EnvironmentValueUnparsable => '{variable} is not a valid inline list or map: {cause}',
            self::EnvironmentVariableConflict => '{variable} and {otherVariable} both set {valuePath}; keep one.',
            self::EnvironmentSecretFileUnreadable => '{variable} points to {path}, which cannot be read.',
        };
    }
}
