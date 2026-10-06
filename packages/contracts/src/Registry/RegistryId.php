<?php

declare(strict_types=1);

namespace Stewart\Contracts\Registry;

use Stewart\Contracts\Exception\IdentifierException;
use Stringable;

abstract readonly class RegistryId implements Stringable
{
    public string $value;

    /** @throws IdentifierException */
    final public function __construct(string $value)
    {
        if ($value === '') {
            throw IdentifierException::registryIdEmpty($this->describeKind());
        }

        $this->value = $value;
    }

    public static function tryFromString(string $value): ?static
    {
        try {
            return new static($value);
        } catch (IdentifierException) {
            return null;
        }
    }

    /** @throws IdentifierException */
    public static function fromStringOrId(self|string $id): static
    {
        return $id instanceof static ? $id : new static((string) $id);
    }

    public function equals(self $other): bool
    {
        return $other::class === static::class && $other->value === $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    abstract protected function describeKind(): string;
}
