<?php

declare(strict_types=1);

namespace Stewart\Contracts\Schedule\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Contracts\Schedule\DayOfWeek;

/** @extends ListCollection<DayOfWeek> */
final readonly class DayOfWeekCollection extends ListCollection
{
    /** @param iterable<DayOfWeek> $days */
    public static function fromDistinctDays(iterable $days): self
    {
        $distinct = [];

        foreach ($days as $day) {
            $distinct[$day->name] = $day;
        }

        return self::fromList($distinct);
    }

    public function containsDay(DayOfWeek $day): bool
    {
        return $this->containsWhere(static fn(DayOfWeek $candidate): bool => $candidate === $day);
    }

    /** @return list<string> */
    public function listNames(): array
    {
        return $this->mapToList(static fn(DayOfWeek $day): string => $day->name);
    }
}
