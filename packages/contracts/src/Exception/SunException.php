<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

/** @extends StewartException<SunError> */
final class SunException extends StewartException
{
    public static function coordinateOutOfRange(string $coordinate, float $value, int $limit): self
    {
        return self::createForReason(SunError::CoordinateOutOfRange, ['coordinate' => $coordinate, 'value' => $value, 'limit' => $limit]);
    }

    public static function locationUnknown(): self
    {
        return self::createForReason(SunError::LocationUnknown);
    }
}
