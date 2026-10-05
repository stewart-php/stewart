<?php

declare(strict_types=1);

namespace Stewart\Contracts\Time;

final readonly class SunOffset
{
    private function __construct(
        private Duration $magnitude,
        private bool $beforeEvent,
    ) {}

    public static function before(Duration $magnitude): self
    {
        return new self($magnitude, true);
    }

    public static function after(Duration $magnitude): self
    {
        return new self($magnitude, false);
    }

    public static function none(): self
    {
        return new self(Duration::zero(), false);
    }

    public function isNone(): bool
    {
        return $this->magnitude->equals(Duration::zero());
    }

    public function isBeforeEvent(): bool
    {
        return $this->beforeEvent && !$this->isNone();
    }

    public function getMagnitude(): Duration
    {
        return $this->magnitude;
    }

    public function applyTo(Instant $eventTime): Instant
    {
        return $this->beforeEvent ? $eventTime->minus($this->magnitude) : $eventTime->plus($this->magnitude);
    }

    public function formatAsHaOffset(): string
    {
        return ($this->isBeforeEvent() ? '-' : '') . $this->magnitude->formatAsClock();
    }
}
