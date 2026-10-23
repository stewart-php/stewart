<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

use Throwable;

/** @extends StewartException<ActionCallError> */
final class ActionCallException extends StewartException
{
    public static function rejected(
        string $domain,
        string $action,
        string $detail,
        ?string $errorCode = null,
        ?Throwable $previous = null,
    ): self {
        return self::createForActionCall(ActionCallError::Rejected, $domain, $action, $detail, $errorCode, $previous);
    }

    public static function unreachable(string $domain, string $action, string $detail, ?Throwable $previous = null): self
    {
        return self::createForActionCall(ActionCallError::Unreachable, $domain, $action, $detail, null, $previous);
    }

    public static function timedOut(string $domain, string $action, string $detail, ?Throwable $previous = null): self
    {
        return self::createForActionCall(ActionCallError::TimedOut, $domain, $action, $detail, null, $previous);
    }

    public static function overloaded(string $domain, string $action, string $detail): self
    {
        return self::createForActionCall(ActionCallError::Overloaded, $domain, $action, $detail, null, null);
    }

    private static function createForActionCall(
        ActionCallError $reason,
        string $domain,
        string $action,
        string $detail,
        ?string $errorCode,
        ?Throwable $previous,
    ): self {
        return self::createForReasonWithAppendedText(
            $reason,
            ['domain' => $domain, 'action' => $action, 'detail' => $detail, 'errorCode' => $errorCode],
            $errorCode === null ? null : '(' . $errorCode . ')',
            $previous,
        );
    }
}
