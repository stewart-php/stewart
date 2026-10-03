<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

use Throwable;

/**
 * @phpstan-import-type ExceptionContext from StewartException
 *
 * @extends StewartException<ServiceCallError>
 */
final class ServiceCallException extends StewartException
{
    public static function rejected(
        string $domain,
        string $service,
        string $detail,
        ?string $errorCode = null,
        ?Throwable $previous = null,
    ): self {
        return self::createForServiceCall(ServiceCallError::Rejected, $domain, $service, $detail, $errorCode, $previous);
    }

    public static function unreachable(string $domain, string $service, string $detail, ?Throwable $previous = null): self
    {
        return self::createForServiceCall(ServiceCallError::Unreachable, $domain, $service, $detail, null, $previous);
    }

    public static function timedOut(string $domain, string $service, string $detail, ?Throwable $previous = null): self
    {
        return self::createForServiceCall(ServiceCallError::TimedOut, $domain, $service, $detail, null, $previous);
    }

    public static function overloaded(string $domain, string $service, string $detail): self
    {
        return self::createForServiceCall(ServiceCallError::Overloaded, $domain, $service, $detail, null, null);
    }

    /** @param ExceptionContext|null $context */
    public static function fromWire(ServiceCallError $reason, string $message, ?array $context): self
    {
        return new self($message, $reason, $context);
    }

    private static function createForServiceCall(
        ServiceCallError $reason,
        string $domain,
        string $service,
        string $detail,
        ?string $errorCode,
        ?Throwable $previous,
    ): self {
        return self::createForReasonWithAppendedText(
            $reason,
            ['domain' => $domain, 'service' => $service, 'detail' => $detail, 'errorCode' => $errorCode],
            $errorCode === null ? null : '(' . $errorCode . ')',
            $previous,
        );
    }
}
