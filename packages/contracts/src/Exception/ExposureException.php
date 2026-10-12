<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Throwable;

/** @extends StewartException<ExposureError> */
final class ExposureException extends StewartException
{
    public static function outsideApp(ExposedEntityKey $key): self
    {
        return self::createForReason(ExposureError::OutsideApp, key: $key->value);
    }

    public static function keyTaken(AppId $appId, ExposedEntityKey $key): self
    {
        return self::createForReason(ExposureError::KeyTaken, appId: $appId->value, key: $key->value);
    }

    public static function configInvalid(string $detail): self
    {
        return self::createForReason(ExposureError::ConfigInvalid, detail: $detail);
    }

    public static function stateInvalid(string $detail): self
    {
        return self::createForReason(ExposureError::StateInvalid, detail: $detail);
    }

    public static function removed(ExposedEntityKey $key): self
    {
        return self::createForReason(ExposureError::Removed, key: $key->value);
    }

    public static function unreachable(ExposedEntityKey $key, string $detail, ?Throwable $previous = null): self
    {
        return self::createForReason(ExposureError::Unreachable, $previous, key: $key->value, detail: $detail);
    }
}
