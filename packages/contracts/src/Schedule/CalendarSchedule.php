<?php

declare(strict_types=1);

namespace Stewart\Contracts\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use Stewart\Contracts\Exception\ScheduleException;
use Stewart\Contracts\Schedule\Collection\DayOfWeekCollection;

/** @internal */
final readonly class CalendarSchedule implements WallClockSchedule
{
    // A weekday whose only match falls in a DST gap lands a week later.
    private const int MAX_SEARCH_DAYS = 15;

    private function __construct(
        public TimeOfDay $time,
        public DayOfWeekCollection $days,
    ) {}

    /** @throws ScheduleException */
    public static function dailyAt(TimeOfDay|string $time, DayOfWeek ...$days): self
    {
        return new self(TimeOfDay::fromTimeOrString($time), DayOfWeekCollection::fromDistinctDays($days));
    }

    public function findNextOccurrenceAfter(DateTimeImmutable $after): ?DateTimeImmutable
    {
        $zone = $after->getTimezone();

        $cursor = new DateTimeImmutable($after->format('Y-m-d'), new DateTimeZone('UTC'));

        for ($offset = 0; $offset <= self::MAX_SEARCH_DAYS; ++$offset) {
            $day = $offset === 0 ? $cursor : $cursor->modify(\sprintf('+%d days', $offset));

            if (!$this->fallsOn($day)) {
                continue;
            }

            $candidate = $this->time->resolveOnDay($day, $zone);

            if ($candidate !== null && $candidate > $after) {
                return $candidate;
            }
        }

        return null;
    }

    public function describe(): string
    {
        if ($this->days->isEmpty()) {
            return \sprintf('daily at %s', $this->time->format());
        }

        return \sprintf(
            'at %s on %s',
            $this->time->format(),
            implode(', ', $this->days->listNames()),
        );
    }

    public function isRecurring(): bool
    {
        return true;
    }

    private function fallsOn(DateTimeImmutable $day): bool
    {
        return $this->days->isEmpty() || $this->days->containsDay(DayOfWeek::fromDateTime($day));
    }
}
