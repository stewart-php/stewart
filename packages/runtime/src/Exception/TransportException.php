<?php

declare(strict_types=1);

namespace Stewart\Runtime\Exception;

use Stewart\Contracts\Exception\StewartException;
use Throwable;

/** @extends StewartException<TransportError> */
final class TransportException extends StewartException
{
    public static function closed(): self
    {
        return self::createForReason(TransportError::Closed);
    }

    public static function sendFailed(Throwable $previous): self
    {
        return self::createForReason(TransportError::SendFailed, [], $previous);
    }

    public static function unencodable(string $messageClass, Throwable $previous): self
    {
        return self::createForReason(TransportError::Unencodable, ['messageClass' => $messageClass], $previous);
    }

    public static function peerDisconnected(Throwable $previous): self
    {
        return self::createForReason(TransportError::PeerDisconnected, [], $previous);
    }

    public static function receiveFailed(Throwable $previous): self
    {
        return self::createForReason(TransportError::ReceiveFailed, [], $previous);
    }

    public static function unexpectedMessage(string $actualType): self
    {
        return self::createForReason(TransportError::UnexpectedMessage, ['actualType' => $actualType]);
    }

    public static function undecodableFrame(string $messageType, string $reason, ?Throwable $previous = null): self
    {
        return self::createForReason(TransportError::UndecodableFrame, ['messageType' => $messageType, 'reason' => $reason], $previous);
    }

    public static function protocolMismatch(int $expected, int $actual): self
    {
        return self::createForReason(TransportError::ProtocolMismatch, ['expected' => $expected, 'actual' => $actual]);
    }

    public static function messageTypeMissing(string $messageClass): self
    {
        return self::createForReason(TransportError::MessageTypeMissing, ['messageClass' => $messageClass]);
    }

    public static function messageTagMissing(string $messageClass): self
    {
        return self::createForReason(TransportError::MessageTagMissing, ['messageClass' => $messageClass]);
    }

    public static function messageTagDuplicated(string $tag, string $firstClass, string $secondClass): self
    {
        return self::createForReason(TransportError::MessageTagDuplicated, ['tag' => $tag, 'firstClass' => $firstClass, 'secondClass' => $secondClass]);
    }
}
