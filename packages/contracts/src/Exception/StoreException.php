<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

use Throwable;

/** @extends StewartException<StoreError> */
final class StoreException extends StewartException
{
    public static function unreachable(string $target, string $detail, ?Throwable $previous = null): self
    {
        return self::createForReason(StoreError::Unreachable, $previous, target: $target, detail: $detail);
    }

    public static function valueNotIncrementable(string $detail, ?Throwable $previous = null): self
    {
        return self::createForReason(StoreError::ValueNotIncrementable, $previous, detail: $detail);
    }

    public function findDetail(): ?string
    {
        return $this->findContextString('detail');
    }
}
