<?php

declare(strict_types=1);

namespace Stewart\Contracts\Schedule;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Stewart\Contracts\Exception\ScheduleException;
use Stewart\Contracts\Time\Instant;

final readonly class TimeOfDay
{
    private const string PATTERN = '/\A(\d{1,2}):(\d{2})(?::(\d{2}))?\z/';

    // A day either side of a DST transition reaches the offsets before and after it.
    private const int TRANSITION_PROBE_SECONDS = 86_400;

    private function __construct(
        public int $hour,
        public int $minute,
        public int $second,
    ) {}

    /** @throws ScheduleException */
    public static function fromHourMinuteSecond(int $hour, int $minute, int $second = 0): self
    {
        self::requireInRange('hour', $hour, 23);
        self::requireInRange('minute', $minute, 59);
        self::requireInRange('second', $second, 59);

        return new self($hour, $minute, $second);
    }

    /** @throws ScheduleException */
    public static function parse(string $time): self
    {
        if (preg_match(self::PATTERN, $time, $matches) !== 1) {
            throw ScheduleException::timeOfDayInvalid($time);
        }

        return self::fromHourMinuteSecond((int) $matches[1], (int) $matches[2], (int) ($matches[3] ?? 0));
    }

    public static function fromDateTime(DateTimeInterface $moment): self
    {
        return new self((int) $moment->format('G'), (int) $moment->format('i'), (int) $moment->format('s'));
    }

    /** @throws ScheduleException */
    public static function fromTimeOrString(self|string $time): self
    {
        return $time instanceof self ? $time : self::parse($time);
    }

    public function format(): string
    {
        return \sprintf('%02d:%02d:%02d', $this->hour, $this->minute, $this->second);
    }

    public function resolveOnDay(DateTimeInterface $day, DateTimeZone $zone): ?DateTimeImmutable
    {
        $wall = $day->format('Y-m-d') . ' ' . $this->format();
        $asUtc = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $wall, new DateTimeZone('UTC'));

        if ($asUtc === false) {
            return null;
        }

        $wallSeconds = $asUtc->getTimestamp();

        /** @var array<int, DateTimeImmutable> $candidates */
        $candidates = [];

        foreach ([-self::TRANSITION_PROBE_SECONDS, self::TRANSITION_PROBE_SECONDS] as $probe) {
            $offset = $zone->getOffset(Instant::fromEpochMicroseconds(($wallSeconds + $probe) * 1_000_000)->toDateTime($zone));
            $moment = Instant::fromEpochMicroseconds(($wallSeconds - $offset) * 1_000_000)->toDateTime($zone);

            if ($moment->format('Y-m-d H:i:s') === $wall) {
                $candidates[$moment->getTimestamp()] = $moment;
            }
        }

        if ($candidates === []) {
            return null;
        }

        return $candidates[min(array_keys($candidates))];
    }

    public function equals(self $other): bool
    {
        return $this->hour === $other->hour
            && $this->minute === $other->minute
            && $this->second === $other->second;
    }

    /** @throws ScheduleException */
    private static function requireInRange(string $unit, int $value, int $highest): void
    {
        if ($value < 0 || $value > $highest) {
            throw ScheduleException::timeOfDayOutOfRange($unit, $value, $highest);
        }
    }
}
