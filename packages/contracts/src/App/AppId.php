<?php

declare(strict_types=1);

namespace Stewart\Contracts\App;

use Stewart\Contracts\Exception\IdentifierException;
use Stringable;

final readonly class AppId implements Stringable
{
    private const string PATTERN = '/\A[a-z][a-z0-9_-]*\z/';

    public string $value;

    /** @throws IdentifierException */
    public function __construct(string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw IdentifierException::appIdInvalid($value);
        }

        $this->value = $value;
    }

    public static function tryFromString(string $value): ?self
    {
        try {
            return new self($value);
        } catch (IdentifierException) {
            return null;
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function collidesWith(self $other): bool
    {
        return $this->toEnvironmentKey() === $other->toEnvironmentKey();
    }

    public function toEnvironmentKey(): string
    {
        // Environment variable names cannot tell "-" from "_".
        return strtr($this->value, '-', '_');
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
