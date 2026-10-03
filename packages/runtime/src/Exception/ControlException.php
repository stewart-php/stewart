<?php

declare(strict_types=1);

namespace Stewart\Runtime\Exception;

use Stewart\Contracts\Exception\StewartException;
use Stewart\Contracts\Time\Duration;
use Throwable;

/** @extends StewartException<ControlError> */
final class ControlException extends StewartException
{
    public static function socketInUse(string $address): self
    {
        return self::createForReason(ControlError::SocketInUse, ['address' => $address]);
    }

    public static function socketUnremovable(string $path, Throwable $previous): self
    {
        return self::createForReason(ControlError::SocketUnremovable, ['path' => $path], $previous);
    }

    public static function socketDirectoryNotCreatable(string $directory, Throwable $previous): self
    {
        return self::createForReason(ControlError::SocketDirectoryNotCreatable, ['directory' => $directory], $previous);
    }

    public static function socketPathNotASocket(string $path): self
    {
        return self::createForReason(ControlError::SocketPathNotASocket, ['path' => $path]);
    }

    public static function frameNotJson(Throwable $previous): self
    {
        return self::createForReason(ControlError::FrameNotJson, [], $previous);
    }

    public static function frameNotAnObject(string $actualType): self
    {
        return self::createForReason(ControlError::FrameNotAnObject, ['actualType' => $actualType]);
    }

    public static function frameUnencodable(string $frameClass, Throwable $previous): self
    {
        return self::createForReason(ControlError::FrameUnencodable, ['frameClass' => $frameClass], $previous);
    }

    public static function frameClassUnknown(string $frameClass): self
    {
        return self::createForReason(ControlError::FrameClassUnknown, ['frameClass' => $frameClass]);
    }

    public static function frameUndecodable(string $frameType, Throwable $previous): self
    {
        return self::createForReason(ControlError::FrameUndecodable, ['frameType' => $frameType], $previous);
    }

    public static function configUnavailable(Throwable $previous): self
    {
        return self::createForReason(ControlError::ConfigUnavailable, [], $previous);
    }

    public static function controlDisabled(): self
    {
        return self::createForReason(ControlError::ControlDisabled);
    }

    public static function tokenMissing(): self
    {
        return self::createForReason(ControlError::TokenMissing);
    }

    public static function connectionRejected(string $reason): self
    {
        return self::createForReason(ControlError::ConnectionRejected, ['reason' => $reason]);
    }

    public static function unexpectedGreeting(string $frameClass): self
    {
        return self::createForReason(ControlError::UnexpectedGreeting, ['frameClass' => $frameClass]);
    }

    public static function protocolMismatch(int $brokerProtocol, int $clientProtocol): self
    {
        return self::createForReason(ControlError::ProtocolMismatch, ['brokerProtocol' => $brokerProtocol, 'clientProtocol' => $clientProtocol]);
    }

    public static function brokerHungUp(): self
    {
        return self::createForReason(ControlError::BrokerHungUp);
    }

    public static function unexpectedFrame(string $frameClass, string $expectedFrame): self
    {
        return self::createForReason(ControlError::UnexpectedFrame, ['frameClass' => $frameClass, 'expectedFrame' => $expectedFrame]);
    }

    public static function snapshotTimedOut(Duration $timeout, Throwable $previous): self
    {
        return self::createForReason(ControlError::SnapshotTimedOut, ['timeout' => (string) $timeout], $previous);
    }
}
