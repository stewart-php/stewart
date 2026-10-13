<?php

declare(strict_types=1);

namespace Stewart\Runtime\Model;

use Stringable;

final readonly class WorkerId implements Stringable
{
    private const string PROCESS_ID_PREFIX = 'w';

    public function __construct(public int $value) {}

    public static function fromInt(int $value): self
    {
        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function toProcessIdPrefix(): string
    {
        return self::PROCESS_ID_PREFIX . $this->value;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
