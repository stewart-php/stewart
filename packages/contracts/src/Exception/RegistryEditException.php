<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

use Stewart\Contracts\Entity\EntityId;
use Throwable;

/**
 * @phpstan-import-type ExceptionContext from StewartException
 *
 * @extends StewartException<RegistryEditError>
 */
final class RegistryEditException extends StewartException
{
    public static function notFound(EntityId $entityId): self
    {
        return self::createForReason(RegistryEditError::NotFound, ['entityId' => $entityId->value]);
    }

    public static function nothingToUpdate(EntityId $entityId): self
    {
        return self::createForReason(RegistryEditError::NothingToUpdate, ['entityId' => $entityId->value]);
    }

    public static function rejected(EntityId $entityId, string $detail, ?string $errorCode = null, ?Throwable $previous = null): self
    {
        return self::createForReasonWithAppendedText(
            RegistryEditError::Rejected,
            ['entityId' => $entityId->value, 'detail' => $detail, 'errorCode' => $errorCode],
            $errorCode === null ? null : '(' . $errorCode . ')',
            $previous,
        );
    }

    public static function unreachable(EntityId $entityId, string $detail, ?Throwable $previous = null): self
    {
        return self::createForReason(RegistryEditError::Unreachable, ['entityId' => $entityId->value, 'detail' => $detail], $previous);
    }

    public static function timedOut(EntityId $entityId, string $detail, ?Throwable $previous = null): self
    {
        return self::createForReason(RegistryEditError::TimedOut, ['entityId' => $entityId->value, 'detail' => $detail], $previous);
    }

    public static function overloaded(EntityId $entityId, string $detail): self
    {
        return self::createForReason(RegistryEditError::Overloaded, ['entityId' => $entityId->value, 'detail' => $detail]);
    }

    /** @param ExceptionContext|null $context */
    public static function fromWire(RegistryEditError $reason, string $message, ?array $context): self
    {
        return new self($message, $reason, $context);
    }
}
