<?php

declare(strict_types=1);

namespace Stewart\Client\Component;

use Stewart\Client\Exception\HaClientException;
use Stringable;

final readonly class ComponentInstance implements Stringable
{
    private const string PATTERN = '/\A[a-z][a-z0-9_]{0,63}\z/';

    private function __construct(public string $value) {}

    /** @throws HaClientException */
    public static function parse(string $value): self
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw HaClientException::componentInstanceInvalid($value);
        }

        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
