<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

use Stewart\Contracts\Exception\ExposureException;
use Stringable;

final readonly class ExposedEntityKey implements Stringable
{
    private const string PATTERN = '/\A[a-z][a-z0-9_]*\z/';

    private const int MAX_LENGTH = 64;

    public string $value;

    /** @throws ExposureException */
    public function __construct(string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1 || \strlen($value) > self::MAX_LENGTH) {
            throw ExposureException::keyInvalid($value, self::MAX_LENGTH);
        }

        $this->value = $value;
    }

    /** @throws ExposureException */
    public static function fromKeyOrString(self|string $key): self
    {
        return $key instanceof self ? $key : new self($key);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
