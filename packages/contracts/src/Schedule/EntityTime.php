<?php

declare(strict_types=1);

namespace Stewart\Contracts\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\ScheduleException;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\Time\Instant;

final readonly class EntityTime
{
    private const string INPUT_DATETIME_DOMAIN = 'input_datetime';

    private const string SENSOR_DOMAIN = 'sensor';

    private const string TIMESTAMP_DEVICE_CLASS = 'timestamp';

    private const string DATE_AND_TIME_PATTERN = '/\A(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})\z/';

    private const string TIME_ONLY_PATTERN = '/\A\d{2}:\d{2}:\d{2}\z/';

    private function __construct(
        public EntityTimeKind $kind,
        public ?TimeOfDay $dailyAt = null,
        public ?Instant $moment = null,
    ) {}

    public static function none(): self
    {
        return new self(EntityTimeKind::None);
    }

    public static function forDailyTime(TimeOfDay $time): self
    {
        return new self(EntityTimeKind::Daily, dailyAt: $time);
    }

    public static function forMoment(Instant $moment): self
    {
        return new self(EntityTimeKind::Moment, moment: $moment);
    }

    /** @throws ScheduleException */
    public static function requireSupportedEntity(EntityId $entityId): void
    {
        if ($entityId->domain !== self::INPUT_DATETIME_DOMAIN && $entityId->domain !== self::SENSOR_DOMAIN) {
            throw ScheduleException::entityTimeDomainUnsupported($entityId->value);
        }
    }

    public static function fromEntityState(?EntityState $state, DateTimeZone $zone): self
    {
        if ($state === null || $state->isUnavailable()) {
            return self::none();
        }

        return match ($state->getDomain()) {
            self::INPUT_DATETIME_DOMAIN => self::fromInputDatetime($state, $zone),
            self::SENSOR_DOMAIN => self::fromTimestampSensor($state),
            default => self::none(),
        };
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind
            && $this->dailyAt?->format() === $other->dailyAt?->format()
            && $this->moment?->toEpochMicroseconds() === $other->moment?->toEpochMicroseconds();
    }

    private static function fromInputDatetime(EntityState $state, DateTimeZone $zone): self
    {
        $hasDate = $state->getBoolAttribute('has_date');
        $hasTime = $state->getBoolAttribute('has_time');

        if ($hasDate !== false && preg_match(self::DATE_AND_TIME_PATTERN, $state->state, $matches) === 1) {
            return self::fromLocalDateAndTime($matches[1], $matches[2], $zone);
        }

        if ($hasDate !== true && $hasTime !== false && preg_match(self::TIME_ONLY_PATTERN, $state->state) === 1) {
            return self::forDailyTime(TimeOfDay::parse($state->state));
        }

        return self::none();
    }

    private static function fromLocalDateAndTime(string $date, string $time, DateTimeZone $zone): self
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
        $moment = $day === false ? null : TimeOfDay::parse($time)->resolveOnDay($day, $zone);

        return $moment === null ? self::none() : self::forMoment(Instant::fromDateTime($moment));
    }

    private static function fromTimestampSensor(EntityState $state): self
    {
        if ($state->getStringAttribute('device_class') !== self::TIMESTAMP_DEVICE_CLASS) {
            return self::none();
        }

        $moment = Instant::tryFromIso($state->state);

        return $moment === null ? self::none() : self::forMoment($moment);
    }
}
