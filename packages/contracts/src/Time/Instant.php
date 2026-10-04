<?php

declare(strict_types=1);

namespace Stewart\Contracts\Time;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Stewart\Contracts\Exception\TimeException;
use Stringable;

final readonly class Instant implements Stringable
{
    private const int MICROS_PER_SECOND = 1_000_000;
    private const string ISO_SHAPE = '/\A\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}(:?\d{2})?)?\z/i';

    private function __construct(private int $epochMicroseconds) {}

    public static function fromEpochMicroseconds(int $epochMicroseconds): self
    {
        return new self($epochMicroseconds);
    }

    public static function fromDateTime(DateTimeInterface $moment): self
    {
        return new self($moment->getTimestamp() * self::MICROS_PER_SECOND + (int) $moment->format('u'));
    }

    /** @throws TimeException */
    public static function fromIso(string $text): self
    {
        if (preg_match(self::ISO_SHAPE, $text) !== 1) {
            throw TimeException::instantUnparsable($text);
        }

        try {
            return self::fromDateTime(new DateTimeImmutable($text, new DateTimeZone('UTC')));
        } catch (Exception) {
            throw TimeException::instantUnparsable($text);
        }
    }

    public static function tryFromIso(?string $text): ?self
    {
        if ($text === null || $text === '') {
            return null;
        }

        try {
            return self::fromIso($text);
        } catch (TimeException) {
            return null;
        }
    }

    public function toEpochMicroseconds(): int
    {
        return $this->epochMicroseconds;
    }

    public function toDateTime(?DateTimeZone $zone = null): DateTimeImmutable
    {
        $seconds = self::floorDiv($this->epochMicroseconds, self::MICROS_PER_SECOND);
        $fraction = $this->epochMicroseconds - $seconds * self::MICROS_PER_SECOND;

        $utc = DateTimeImmutable::createFromFormat('U.u', \sprintf('%d.%06d', $seconds, $fraction), new DateTimeZone('UTC'));
        \assert($utc !== false);

        return $utc->setTimezone($zone ?? new DateTimeZone(date_default_timezone_get()));
    }

    public function toIso8601(): string
    {
        return $this->toDateTime(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    public function plus(Duration $duration): self
    {
        return new self($this->epochMicroseconds + $duration->toMicroseconds());
    }

    public function minus(Duration $duration): self
    {
        return new self($this->epochMicroseconds - $duration->toMicroseconds());
    }

    public function elapsedSince(self $earlier): Duration
    {
        return Duration::microseconds(max(0, $this->epochMicroseconds - $earlier->epochMicroseconds));
    }

    public function isBefore(self $other): bool
    {
        return $this->epochMicroseconds < $other->epochMicroseconds;
    }

    public function isAfter(self $other): bool
    {
        return $this->epochMicroseconds > $other->epochMicroseconds;
    }

    public function equals(self $other): bool
    {
        return $this->epochMicroseconds === $other->epochMicroseconds;
    }

    public function __toString(): string
    {
        return $this->toIso8601();
    }

    private static function floorDiv(int $dividend, int $divisor): int
    {
        return intdiv($dividend - (($dividend % $divisor) + $divisor) % $divisor, $divisor);
    }
}
