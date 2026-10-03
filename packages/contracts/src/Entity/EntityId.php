<?php

declare(strict_types=1);

namespace Stewart\Contracts\Entity;

use Stewart\Contracts\Exception\IdentifierException;
use Stringable;

final readonly class EntityId implements Stringable
{
    private const string PATTERN = '/\A([a-z0-9_]+)\.([a-z0-9_]+)\z/';

    public string $value;

    public string $domain;

    public string $objectId;

    /** @throws IdentifierException */
    public function __construct(string $value)
    {
        if (preg_match(self::PATTERN, $value, $parts) !== 1) {
            throw IdentifierException::entityIdInvalid($value);
        }

        $this->value = $value;
        $this->domain = $parts[1];
        $this->objectId = $parts[2];
    }

    public static function tryFromString(string $value): ?self
    {
        try {
            return new self($value);
        } catch (IdentifierException) {
            return null;
        }
    }

    /** @throws IdentifierException */
    public static function fromStringOrId(self|string $id): self
    {
        return $id instanceof self ? $id : new self($id);
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
