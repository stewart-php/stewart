<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Stewart\Contracts\Exception\TimeException;
use Stewart\Contracts\Time\Duration;
use Stringable;

final readonly class OptionalDuration implements Stringable
{
    private function __construct(private ?Duration $duration) {}

    /** @throws TimeException */
    public static function parse(string $text): self
    {
        return strcasecmp(trim($text), StewartConfigSchema::OFF) === 0 ? self::off() : new self(Duration::parse($text));
    }

    public static function off(): self
    {
        return new self(null);
    }

    public function findDuration(): ?Duration
    {
        return $this->duration;
    }

    public function __toString(): string
    {
        return $this->duration === null ? StewartConfigSchema::OFF : (string) $this->duration;
    }
}
