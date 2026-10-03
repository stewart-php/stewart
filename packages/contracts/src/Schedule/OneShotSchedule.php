<?php

declare(strict_types=1);

namespace Stewart\Contracts\Schedule;

use DateTimeImmutable;

/** @internal */
final readonly class OneShotSchedule implements WallClockSchedule
{
    private function __construct(public DateTimeImmutable $moment) {}

    public static function fromMoment(DateTimeImmutable $moment): self
    {
        return new self($moment);
    }

    public function findNextOccurrenceAfter(DateTimeImmutable $after): ?DateTimeImmutable
    {
        return $this->moment > $after ? $this->moment->setTimezone($after->getTimezone()) : null;
    }

    public function describe(): string
    {
        return \sprintf('once at %s', $this->moment->format('c'));
    }

    public function isRecurring(): bool
    {
        return false;
    }
}
