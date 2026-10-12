<?php

declare(strict_types=1);

namespace Stewart\Support\Schedule;

use DateTimeInterface;
use Stewart\Contracts\Exception\ScheduleError;
use Stewart\Contracts\Exception\ScheduleException;
use Stewart\Contracts\Schedule\TimeOfDay;

/** @internal */
final readonly class TimeWindow
{
    private function __construct(
        public TimeOfDay $start,
        public TimeOfDay $end,
    ) {}

    /** @throws ScheduleException */
    public static function between(TimeOfDay|string $start, TimeOfDay|string $end): self
    {
        $start = TimeOfDay::fromTimeOrString($start);
        $end = TimeOfDay::fromTimeOrString($end);

        if ($start->equals($end)) {
            throw ScheduleException::createForReason(ScheduleError::TimeWindowEmpty, time: $start->format());
        }

        return new self($start, $end);
    }

    public function includes(DateTimeInterface $moment): bool
    {
        $seconds = TimeOfDay::fromDateTime($moment)->toSecondsOfDay();
        $afterStart = $seconds >= $this->start->toSecondsOfDay();
        $beforeEnd = $seconds < $this->end->toSecondsOfDay();

        return $this->crossesMidnight() ? $afterStart || $beforeEnd : $afterStart && $beforeEnd;
    }

    public function crossesMidnight(): bool
    {
        return $this->end->toSecondsOfDay() < $this->start->toSecondsOfDay();
    }
}
