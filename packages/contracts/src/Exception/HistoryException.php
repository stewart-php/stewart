<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Time\Instant;
use Throwable;

/**
 * @phpstan-import-type ExceptionContext from StewartException
 *
 * @extends StewartException<HistoryError>
 */
final class HistoryException extends StewartException
{
    public static function windowInvalid(Instant $startsAt, Instant $endsAt): self
    {
        return self::createForReason(HistoryError::WindowInvalid, ['startsAt' => (string) $startsAt, 'endsAt' => (string) $endsAt]);
    }

    public static function recorderUnavailable(EntityId $entityId, ?Throwable $previous = null): self
    {
        return self::createForReason(HistoryError::RecorderUnavailable, ['entityId' => $entityId->value], $previous);
    }

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
        return self::createForReason(HistoryError::Unreachable, ['entityId' => $entityId->value, 'detail' => $detail], $previous);
    }

    public static function timedOut(EntityId $entityId, string $detail, ?Throwable $previous = null): self
    {
        return self::createForReason(HistoryError::TimedOut, ['entityId' => $entityId->value, 'detail' => $detail], $previous);
    }

    /** @param ExceptionContext|null $context */
    public static function fromWire(HistoryError $reason, string $message, ?array $context): self
    {
        return new self($message, $reason, $context);
    }
}
