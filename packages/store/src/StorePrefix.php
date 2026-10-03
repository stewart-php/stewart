<?php

declare(strict_types=1);

namespace Stewart\Store;

use Stewart\Store\Exception\StoreSetupException;
use Stringable;

final readonly class StorePrefix implements Stringable
{
    /** @throws StoreSetupException */
    public function __construct(public string $value)
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $value) !== 1) {
            throw StoreSetupException::prefixInvalid($value);
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
