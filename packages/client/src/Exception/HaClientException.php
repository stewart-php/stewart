<?php

declare(strict_types=1);

namespace Stewart\Client\Exception;

use Stewart\Contracts\Exception\StewartException;
use Throwable;

/** @extends StewartException<HaClientError> */
final class HaClientException extends StewartException
{
    public static function connectionDropped(string $detail, ?Throwable $previous = null): self
    {
        return self::createForReason(HaClientError::ConnectionDropped, $previous, detail: $detail);
    }

    public static function protocolViolation(string $detail, ?Throwable $previous = null): self
    {
        return self::createForReason(HaClientError::ProtocolViolation, $previous, detail: $detail);
    }

    public static function commandUnencodable(string $command, Throwable $previous): self
    {
        return self::createForReason(HaClientError::CommandUnencodable, $previous, command: $command, detail: 'could not be encoded: ' . $previous->getMessage());
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
