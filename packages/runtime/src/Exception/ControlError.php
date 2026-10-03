<?php

declare(strict_types=1);

namespace Stewart\Runtime\Exception;

use Stewart\Contracts\Exception\ExceptionReason;

enum ControlError: string implements ExceptionReason
{
    case SocketInUse = 'socket_in_use';
    case SocketUnremovable = 'socket_unremovable';
    case SocketDirectoryNotCreatable = 'socket_directory_not_creatable';
    case SocketPathNotASocket = 'socket_path_not_a_socket';
    case FrameNotJson = 'frame_not_json';
    case FrameNotAnObject = 'frame_not_an_object';
    case FrameUnencodable = 'frame_unencodable';
    case FrameClassUnknown = 'frame_class_unknown';
    case FrameUndecodable = 'frame_undecodable';
    case ConfigUnavailable = 'config_unavailable';
    case ControlDisabled = 'control_disabled';
    case TokenMissing = 'token_missing';
    case ConnectionRejected = 'connection_rejected';
    case UnexpectedGreeting = 'unexpected_greeting';
    case ProtocolMismatch = 'protocol_mismatch';
    case BrokerHungUp = 'broker_hung_up';
    case UnexpectedFrame = 'unexpected_frame';
    case SnapshotTimedOut = 'snapshot_timed_out';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::SocketInUse => 'Another daemon is already listening on {address}. Stop it or change control.listen.',
            self::SocketUnremovable => 'Could not remove the stale control socket {path}.',
            self::SocketDirectoryNotCreatable => 'Could not create control socket directory {directory}.',
            self::SocketPathNotASocket => 'The control socket path {path} exists and is not a socket.',
            self::FrameNotJson => 'Frame is not JSON: {cause}',
            self::FrameNotAnObject => 'Frame is {actualType}; expected a JSON object.',
            self::FrameUnencodable => 'Could not encode {frameClass}: {cause}',
            self::FrameClassUnknown => 'Unknown frame {frameClass}.',
            self::FrameUndecodable => 'Could not decode the "{frameType}" control frame: {cause}',
            self::ConfigUnavailable => 'Could not read control.listen and control.token (pass --address and --token): {cause}',
            self::ControlDisabled => 'The configuration sets control.listen to off; pass --address.',
            self::TokenMissing => 'No control token; set STEWART_CONTROL__TOKEN or pass --token.',
            self::ConnectionRejected => 'The broker refused the connection: {reason}',
            self::UnexpectedGreeting => 'The broker sent {frameClass} instead of a welcome.',
            self::ProtocolMismatch => 'Broker speaks control protocol {brokerProtocol}, this client {clientProtocol}.',
            self::BrokerHungUp => 'The broker hung up before the session ended.',
            self::UnexpectedFrame => 'The broker sent {frameClass} instead of {expectedFrame}.',
            self::SnapshotTimedOut => 'The broker sent no snapshot within {timeout}.',
        };
    }
}
