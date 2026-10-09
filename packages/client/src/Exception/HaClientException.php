<?php

declare(strict_types=1);

namespace Stewart\Client\Exception;

use Stewart\Contracts\Exception\StewartException;
use Stewart\Contracts\Time\Duration;
use Throwable;

/** @extends StewartException<HaClientError> */
final class HaClientException extends StewartException
{
    public static function closedLocally(string $phase): self
    {
        return self::createForReason(HaClientError::ClosedLocally, ['phase' => $phase]);
    }

    public static function connectTimedOut(string $url, Duration $timeout, ?Throwable $previous = null): self
    {
        return self::createForReason(HaClientError::ConnectTimedOut, ['url' => $url, 'timeout' => (string) $timeout], $previous);
    }

    public static function connectFailed(string $url, Throwable $previous): self
    {
        return self::createForReason(HaClientError::ConnectFailed, ['url' => $url], $previous);
    }

    public static function notConnected(): self
    {
        return self::createForReason(HaClientError::NotConnected);
    }

    public static function sendFailed(string $command, Throwable $previous): self
    {
        return self::createForReason(HaClientError::SendFailed, ['command' => $command], $previous);
    }

    public static function closedDuringAuthentication(): self
    {
        return self::createForReason(HaClientError::ClosedDuringAuthentication);
    }

    public static function connectionDropped(string $detail, ?Throwable $previous = null): self
    {
        return self::createForReason(HaClientError::ConnectionDropped, ['detail' => $detail], $previous);
    }

    public static function messageTooLarge(): self
    {
        return self::createForReason(HaClientError::MessageTooLarge);
    }

    public static function protocolViolation(string $detail, ?Throwable $previous = null): self
    {
        return self::createForReason(HaClientError::ProtocolViolation, ['detail' => $detail], $previous);
    }

    public static function commandTimedOut(string $command, Duration $timeout, Throwable $previous): self
    {
        return self::createForReason(HaClientError::CommandTimedOut, ['command' => $command, 'timeout' => (string) $timeout], $previous);
    }

    public static function commandRejected(string $command, string $detail, ?string $errorCode): self
    {
        return self::createForReason(HaClientError::CommandRejected, ['command' => $command, 'detail' => $detail, 'errorCode' => $errorCode]);
    }

    public static function commandUnauthorized(string $command, string $detail, string $errorCode): self
    {
        return self::createForReason(HaClientError::CommandUnauthorized, ['command' => $command, 'detail' => $detail, 'errorCode' => $errorCode]);
    }

    public static function commandUnencodable(string $command, Throwable $previous): self
    {
        return self::createForReason(HaClientError::CommandUnencodable, ['command' => $command, 'detail' => 'could not be encoded: ' . $previous->getMessage()], $previous);
    }

    public static function unexpectedGreeting(string $receivedType): self
    {
        return self::createForReason(HaClientError::UnexpectedGreeting, ['receivedType' => $receivedType]);
    }

    public static function tokenRejected(string $reason): self
    {
        return self::createForReason(HaClientError::TokenRejected, ['reason' => $reason]);
    }

    public static function administratorRequired(Throwable $previous): self
    {
        return self::createForReason(HaClientError::AdministratorRequired, [], $previous);
    }

    public static function eventBacklogExceeded(int $limit): self
    {
        return self::createForReason(HaClientError::EventBacklogExceeded, ['limit' => $limit]);
    }

    public static function eventQueueReentered(): self
    {
        return self::createForReason(HaClientError::EventQueueReentered);
    }

    public static function urlInvalid(string $url): self
    {
        return self::createForReason(HaClientError::UrlInvalid, ['url' => $url]);
    }

    public static function componentInstanceInvalid(string $instance): self
    {
        return self::createForReason(HaClientError::ComponentInstanceInvalid, ['instance' => $instance]);
    }

    public function findDetail(): ?string
    {
        return $this->findContextString('detail');
    }

    public function findErrorCode(): ?string
    {
        return $this->findContextString('errorCode');
    }
}
