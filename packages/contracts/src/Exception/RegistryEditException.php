<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

use Stewart\Contracts\Entity\EntityId;
use Throwable;

/** @extends StewartException<RegistryEditError> */
final class RegistryEditException extends StewartException
{
    public static function notFound(EntityId $entityId): self
    {
        return self::createForReason(RegistryEditError::NotFound, entityId: $entityId->value);
    }

    public static function nothingToUpdate(EntityId $entityId): self
    {
        return self::createForReason(RegistryEditError::NothingToUpdate, entityId: $entityId->value);
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
        return self::createForReason(RegistryEditError::Unreachable, $previous, entityId: $entityId->value, detail: $detail);
    }

    public static function timedOut(EntityId $entityId, string $detail, ?Throwable $previous = null): self
    {
        return self::createForReason(RegistryEditError::TimedOut, $previous, entityId: $entityId->value, detail: $detail);
    }
}
