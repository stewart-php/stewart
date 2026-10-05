<?php

declare(strict_types=1);

namespace Stewart\Client\Exception;

use Stewart\Contracts\Exception\ExceptionReason;

enum HaClientError: string implements ExceptionReason
{
    case ClosedLocally = 'closed_locally';
    case ConnectTimedOut = 'connect_timed_out';
    case ConnectFailed = 'connect_failed';
    case NotConnected = 'not_connected';
    case SendFailed = 'send_failed';
    case ClosedDuringAuthentication = 'closed_during_authentication';
    case ConnectionDropped = 'connection_dropped';
    case MessageTooLarge = 'message_too_large';
    case ProtocolViolation = 'protocol_violation';
    case CommandTimedOut = 'command_timed_out';
    case CommandRejected = 'command_rejected';
    case CommandUnauthorized = 'command_unauthorized';
    case CommandUnencodable = 'command_unencodable';
    case UnexpectedGreeting = 'unexpected_greeting';
    case TokenRejected = 'token_rejected';
    case AdministratorRequired = 'administrator_required';
    case EventBacklogExceeded = 'event_backlog_exceeded';
    case EventQueueReentered = 'event_queue_reentered';
    case UrlInvalid = 'url_invalid';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::ClosedLocally => 'The connection was closed locally while {phase}.',
            self::ConnectTimedOut => 'Connecting to Home Assistant at {url} timed out after {timeout}. Check home_assistant.url.',
            self::ConnectFailed => 'Could not connect to Home Assistant at {url}: {cause}',
            self::NotConnected => 'Not connected to Home Assistant.',
            self::SendFailed => 'Failed to send {command}: {cause}',
            self::ClosedDuringAuthentication => 'Home Assistant closed the connection during authentication.',
            self::ConnectionDropped => 'The connection to Home Assistant was lost ({detail}).',
            self::MessageTooLarge => 'Home Assistant sent a message over the size limit, so the connection was dropped. Raise home_assistant.message_size_limit or home_assistant.frame_size_limit.',
            self::ProtocolViolation => 'Home Assistant sent {detail}.',
            self::CommandTimedOut => 'Home Assistant did not answer {command} within {timeout}.',
            self::CommandRejected, self::CommandUnauthorized => '{command} failed: {detail}',
            self::CommandUnencodable => '{command} could not be encoded: {cause}',
            self::UnexpectedGreeting => 'Expected auth_required from Home Assistant, got "{receivedType}".',
            self::TokenRejected => 'Home Assistant rejected the access token: {reason}. Check STEWART_HOME_ASSISTANT__TOKEN.',
            self::AdministratorRequired => 'Home Assistant refused the event subscription; the access token must belong to an administrator. Update STEWART_HOME_ASSISTANT__TOKEN.',
            self::EventBacklogExceeded => 'Event handlers are more than {limit} events behind; dropping the connection.',
            self::EventQueueReentered => 'An event callback cannot wait for the event queue it is being delivered by.',
            self::UrlInvalid => '"{url}" is not a Home Assistant URL; use http://, https://, ws:// or wss:// and a host.',
        };
    }

    public function isFatal(): bool
    {
        return match ($this) {
            self::UnexpectedGreeting, self::TokenRejected, self::AdministratorRequired => true,
            default => false,
        };
    }

    public function isCommandRefusal(): bool
    {
        return match ($this) {
            self::CommandRejected, self::CommandUnauthorized, self::CommandUnencodable, self::AdministratorRequired => true,
            default => false,
        };
    }
}
