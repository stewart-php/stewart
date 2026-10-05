<?php

declare(strict_types=1);

namespace Stewart\Contracts\Sun;

use DateTimeImmutable;
use Stewart\Contracts\Time\Instant;

final readonly class SunDay
{
    /** @param array<string, Instant> $eventTimes */
    private function __construct(
        public DateTimeImmutable $date,
        private array $eventTimes,
    ) {}

    public static function forDate(DateTimeImmutable $date): self
    {
        return new self($date->setTime(0, 0), []);
    }

    public function withEventTime(SunEvent $event, Instant $time): self
    {
        return new self($this->date, [...$this->eventTimes, $event->value => $time]);
    }

    public function findEventTime(SunEvent $event): ?Instant
    {
        return $this->eventTimes[$event->value] ?? null;
    }
}
