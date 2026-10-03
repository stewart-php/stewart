<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

use Stewart\Contracts\Time\Duration;
use Throwable;

/** @extends StewartException<StoreError> */
final class StoreException extends StewartException
{
    public static function keyInvalid(string $key): self
    {
        return self::createForReason(StoreError::KeyInvalid, ['key' => $key]);
    }

    public static function valueNotEncodable(string $key, Throwable $previous): self
    {
        return self::createForReason(StoreError::ValueNotEncodable, ['key' => $key], $previous);
    }

    public static function valueNotAMap(string $key, string $class): self
    {
        return self::createForReason(StoreError::ValueNotAMap, ['key' => $key, 'class' => $class]);
    }

    public static function notConfigured(): self
    {
        return self::createForReason(StoreError::NotConfigured);
    }

    public static function unreachable(string $target, string $detail, ?Throwable $previous = null): self
    {
        return self::createForReason(StoreError::Unreachable, ['target' => $target, 'detail' => $detail], $previous);
    }

    public static function refused(string $target, string $detail, ?Throwable $previous = null): self
    {
        return self::createForReason(StoreError::Refused, ['target' => $target, 'detail' => $detail], $previous);
    }

    public static function timedOut(string $target, Duration $timeout, ?Throwable $previous = null): self
    {
        return self::createForReason(StoreError::TimedOut, ['target' => $target, 'timeout' => (string) $timeout], $previous);
    }

    public static function recentlyFailed(string $target, string $detail): self
    {
        return self::createForReason(StoreError::RecentlyFailed, ['target' => $target, 'detail' => $detail]);
    }

    public static function typeMismatch(string $key, string $expected, string $actual): self
    {
        return self::createForReason(StoreError::TypeMismatch, ['key' => $key, 'expected' => $expected, 'actual' => $actual]);
    }

    public static function notIncrementable(string $key, string $detail, ?Throwable $previous = null): self
    {
        return self::createForReason(StoreError::NotIncrementable, ['key' => $key, 'detail' => $detail], $previous);
    }

    public static function valueNotIncrementable(string $detail, ?Throwable $previous = null): self
    {
        return self::createForReason(StoreError::ValueNotIncrementable, ['detail' => $detail], $previous);
    }

    public static function valueMalformed(string $key, Throwable $previous): self
    {
        return self::createForReason(StoreError::ValueMalformed, ['key' => $key], $previous);
    }

    public static function valueRejected(string $key, string $class, Throwable $previous): self
    {
        return self::createForReason(StoreError::ValueRejected, ['key' => $key, 'class' => $class], $previous);
    }

    public function findDetail(): ?string
    {
        return $this->findContextString('detail');
    }
}
