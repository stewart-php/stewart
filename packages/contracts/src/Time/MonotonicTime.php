<?php

declare(strict_types=1);

namespace Stewart\Contracts\Time;

final readonly class MonotonicTime
{
    private function __construct(private int $microseconds) {}

    public static function fromMicroseconds(int $microseconds): self
    {
        return new self($microseconds);
    }

    public function toMicroseconds(): int
    {
        return $this->microseconds;
    }

    public function plus(Duration $duration): self
    {
        return new self($this->microseconds + $duration->toMicroseconds());
    }

    public function elapsedSince(self $earlier): Duration
    {
        return Duration::microseconds(max(0, $this->microseconds - $earlier->microseconds));
    }

    public function isBefore(self $other): bool
    {
        return $this->microseconds < $other->microseconds;
    }

    public function isAfter(self $other): bool
    {
        return $this->microseconds > $other->microseconds;
    }

    public function equals(self $other): bool
    {
        return $this->microseconds === $other->microseconds;
    }
}
