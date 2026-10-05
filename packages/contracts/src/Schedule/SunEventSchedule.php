<?php

declare(strict_types=1);

namespace Stewart\Contracts\Schedule;

use DateTimeImmutable;
use Stewart\Contracts\Exception\ScheduleException;
use Stewart\Contracts\Exception\SunException;
use Stewart\Contracts\Sun\SunCalendar;
use Stewart\Contracts\Sun\SunEvent;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Time\SunOffset;

/** @internal */
final readonly class SunEventSchedule implements WallClockSchedule
{
    private function __construct(
        private SunCalendar $calendar,
        public SunEvent $event,
        public SunOffset $offset,
    ) {}

    /** @throws ScheduleException */
    public static function forEvent(SunCalendar $calendar, SunEvent $event, ?SunOffset $offset = null): self
    {
        try {
            $calendar->getLocation();
        } catch (SunException $e) {
            throw ScheduleException::sunLocationUnknown($event->describe(), $e);
        }

        return new self($calendar, $event, $offset ?? SunOffset::none());
    }

    public function findNextOccurrenceAfter(DateTimeImmutable $after): ?DateTimeImmutable
    {
        return $this->calendar->findNextEvent($this->event, $this->offset, Instant::fromDateTime($after))?->toDateTime($after->getTimezone());
    }

    public function describe(): string
    {
        if ($this->offset->isNone()) {
            return 'at ' . $this->event->describe();
        }

        return \sprintf('%s %s %s', $this->offset->getMagnitude(), $this->offset->isBeforeEvent() ? 'before' : 'after', $this->event->describe());
    }

    public function isRecurring(): bool
    {
        return true;
    }
}
