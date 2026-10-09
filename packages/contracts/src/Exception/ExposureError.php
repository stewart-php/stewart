<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

enum ExposureError: string implements ExceptionReason
{
    case ComponentMissing = 'exposure_component_missing';
    case ProtocolMismatch = 'exposure_protocol_mismatch';
    case ComponentRefused = 'exposure_component_refused';
    case OutsideApp = 'exposure_outside_app';
    case KeyInvalid = 'exposure_key_invalid';
    case KeyTaken = 'exposure_key_taken';
    case ConfigInvalid = 'exposure_config_invalid';
    case StateInvalid = 'exposure_state_invalid';
    case Removed = 'exposure_removed';
    case Unreachable = 'exposure_unreachable';
    case TimedOut = 'exposure_timed_out';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::ComponentMissing => 'Entity {key} cannot be exposed because the stewart Home Assistant integration is not installed; see `stewart status`.',
            self::ProtocolMismatch => 'Entity {key} cannot be exposed because the stewart Home Assistant integration speaks another protocol; see `stewart status`.',
            self::ComponentRefused => 'Entity {key} cannot be exposed because Home Assistant refused the stewart integration session; see `stewart status`.',
            self::OutsideApp => 'Entity {key} cannot be exposed outside an app, because its unique ID needs an app ID.',
            self::KeyInvalid => 'Exposed entity key "{key}" is invalid; use up to {limit} lowercase letters, digits and "_", starting with a letter.',
            self::KeyTaken => 'App {appId} already exposes an entity with key {key}.',
            self::ConfigInvalid => 'Home Assistant cannot use this entity configuration: {detail}',
            self::StateInvalid => 'Home Assistant cannot use this entity state: {detail}',
            self::Removed => 'Exposed entity {key} was removed; expose it again to use it.',
            self::Unreachable => 'Could not reach the broker for exposed entity {key}: {detail}',
            self::TimedOut => 'The broker did not answer for exposed entity {key} within {timeout}.',
        };
    }
}
