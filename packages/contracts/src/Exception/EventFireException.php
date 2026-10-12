<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

use Throwable;

/**
 * @phpstan-import-type ExceptionContext from StewartException
 *
 * @extends StewartException<EventFireError>
 */
final class EventFireException extends StewartException
{
    public static function rejected(string $eventType, string $detail, ?string $errorCode = null, ?Throwable $previous = null): self
    {
        return self::createForReasonWithAppendedText(
            EventFireError::Rejected,
            ['eventType' => $eventType, 'detail' => $detail, 'errorCode' => $errorCode],
            $errorCode === null ? null : '(' . $errorCode . ')',
            $previous,
        );
    }

    public static function unreachable(string $eventType, string $detail, ?Throwable $previous = null): self
    {
        return self::createForReason(EventFireError::Unreachable, $previous, eventType: $eventType, detail: $detail);
    }

    public static function timedOut(string $eventType, string $detail, ?Throwable $previous = null): self
    {
        return self::createForReason(EventFireError::TimedOut, $previous, eventType: $eventType, detail: $detail);
    }

    /** @param ExceptionContext|null $context */
    public static function fromWire(EventFireError $reason, string $message, ?array $context): self
    {
        return new self($message, $reason, $context);
    }
}
