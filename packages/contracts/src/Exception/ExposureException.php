<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Time\Duration;
use Throwable;

/**
 * @phpstan-import-type ExceptionContext from StewartException
 *
 * @extends StewartException<ExposureError>
 */
final class ExposureException extends StewartException
{
    public static function componentMissing(ExposedEntityKey $key): self
    {
        return self::createForReason(ExposureError::ComponentMissing, ['key' => $key->value]);
    }

    public static function protocolMismatch(ExposedEntityKey $key): self
    {
        return self::createForReason(ExposureError::ProtocolMismatch, ['key' => $key->value]);
    }

    public static function componentRefused(ExposedEntityKey $key): self
    {
        return self::createForReason(ExposureError::ComponentRefused, ['key' => $key->value]);
    }

    public static function outsideApp(ExposedEntityKey $key): self
    {
        return self::createForReason(ExposureError::OutsideApp, ['key' => $key->value]);
    }

    public static function keyInvalid(string $key, int $limit): self
    {
        return self::createForReason(ExposureError::KeyInvalid, ['key' => $key, 'limit' => $limit]);
    }

    public static function keyTaken(AppId $appId, ExposedEntityKey $key): self
    {
        return self::createForReason(ExposureError::KeyTaken, ['appId' => $appId->value, 'key' => $key->value]);
    }

    public static function configInvalid(string $detail): self
    {
        return self::createForReason(ExposureError::ConfigInvalid, ['detail' => $detail]);
    }

    public static function stateInvalid(string $detail): self
    {
        return self::createForReason(ExposureError::StateInvalid, ['detail' => $detail]);
    }

    public static function removed(ExposedEntityKey $key): self
    {
        return self::createForReason(ExposureError::Removed, ['key' => $key->value]);
    }

    public static function unreachable(ExposedEntityKey $key, string $detail, ?Throwable $previous = null): self
    {
        return self::createForReason(ExposureError::Unreachable, ['key' => $key->value, 'detail' => $detail], $previous);
    }

    public static function timedOut(ExposedEntityKey $key, Duration $timeout): self
    {
        return self::createForReason(ExposureError::TimedOut, ['key' => $key->value, 'timeout' => (string) $timeout]);
    }

    /** @param ExceptionContext|null $context */
    public static function fromWire(ExposureError $reason, string $message, ?array $context): self
    {
        return new self($message, $reason, $context);
    }
}
