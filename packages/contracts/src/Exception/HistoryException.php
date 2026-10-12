<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

use Stewart\Contracts\Entity\EntityId;
use Throwable;

/** @extends StewartException<HistoryError> */
final class HistoryException extends StewartException
{
    public static function rejected(EntityId $entityId, string $detail, ?string $errorCode = null, ?Throwable $previous = null): self
    {
        return self::createForReasonWithAppendedText(
            HistoryError::Rejected,
            ['entityId' => $entityId->value, 'detail' => $detail, 'errorCode' => $errorCode],
            $errorCode === null ? null : '(' . $errorCode . ')',
            $previous,
        );
    }

    public static function unreachable(EntityId $entityId, string $detail, ?Throwable $previous = null): self
    {
        return self::createForReason(HistoryError::Unreachable, $previous, entityId: $entityId->value, detail: $detail);
    }

    public static function timedOut(EntityId $entityId, string $detail, ?Throwable $previous = null): self
    {
        return self::createForReason(HistoryError::TimedOut, $previous, entityId: $entityId->value, detail: $detail);
    }
}
