<?php

declare(strict_types=1);

namespace Stewart\Runtime\Exception;

use Stewart\Contracts\Exception\ExceptionReason;

enum TransportError: string implements ExceptionReason
{
    case Closed = 'closed';
    case SendFailed = 'send_failed';
    case Unencodable = 'unencodable';
    case PeerDisconnected = 'peer_disconnected';
    case ReceiveFailed = 'receive_failed';
    case UnexpectedMessage = 'unexpected_message';
    case UndecodableFrame = 'undecodable_frame';
    case ProtocolMismatch = 'protocol_mismatch';
    case MessageTypeMissing = 'message_type_missing';
    case MessageTagMissing = 'message_tag_missing';
    case MessageTagDuplicated = 'message_tag_duplicated';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::Closed => 'Transport is closed.',
            self::SendFailed => 'Failed to send to peer: {cause}',
            self::Unencodable => 'Could not encode {messageClass} for IPC: {cause}',
            self::PeerDisconnected => 'Peer disconnected: {cause}',
            self::ReceiveFailed => 'Failed to receive from peer: {cause}',
            self::UnexpectedMessage => 'Expected a JSON frame, got {actualType}.',
            self::UndecodableFrame => 'Could not decode "{messageType}" frame: {reason}',
            self::ProtocolMismatch => 'Broker speaks IPC protocol {actual}, this worker {expected}.',
            self::MessageTypeMissing => 'No IPC message type is registered for {messageClass}.',
            self::MessageTagMissing => 'IPC message {messageClass} has no #[IpcMessage] attribute.',
            self::MessageTagDuplicated => 'IPC tag "{tag}" is declared by both {firstClass} and {secondClass}.',
        };
    }
}
