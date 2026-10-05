<?php

declare(strict_types=1);

namespace Stewart\Contracts\Time;

use Stewart\Contracts\Exception\TimeException;
use Stringable;

final readonly class Duration implements Stringable
{
    private const array UNITS = ['h' => 3_600_000_000, 'm' => 60_000_000, 's' => 1_000_000, 'ms' => 1_000, 'us' => 1];

    private function __construct(private int $microseconds) {}

    public static function zero(): self
    {
        return new self(0);
    }

    /** @throws TimeException */
    public static function microseconds(int $microseconds): self
    {
        if ($microseconds < 0) {
            throw TimeException::durationNegative($microseconds);
        }

        return new self($microseconds);
    }

    /** @throws TimeException */
    public static function milliseconds(int|float $milliseconds): self
    {
        return self::microseconds(self::scale($milliseconds, self::UNITS['ms']));
    }

    /** @throws TimeException */
    public static function seconds(int|float $seconds): self
    {
        return self::microseconds(self::scale($seconds, self::UNITS['s']));
    }

    /** @throws TimeException */
    public static function minutes(int|float $minutes): self
    {
        return self::microseconds(self::scale($minutes, self::UNITS['m']));
    }

    /** @throws TimeException */
    public static function hours(int|float $hours): self
    {
        return self::microseconds(self::scale($hours, self::UNITS['h']));
    }

    /** @throws TimeException */
    public static function parse(string $text): self
    {
        $segments = preg_split('/\s+/', trim($text), flags: \PREG_SPLIT_NO_EMPTY);
        $microseconds = 0;

        foreach ($segments === false || $segments === [] ? [$text] : $segments as $segment) {
            if (preg_match('/\A(\d+(?:\.\d+)?)(us|ms|s|m|h)\z/', $segment, $match) !== 1) {
                throw TimeException::durationUnparsable($text);
            }

            $microseconds += self::scale((float) $match[1], self::UNITS[$match[2]]);
        }

        return self::microseconds($microseconds);
    }

    /** @throws TimeException */
    public function requireAtLeastOneMillisecond(string $usage): self
    {
        if ($this->microseconds < self::UNITS['ms']) {
            throw TimeException::durationNotPositive($usage);
        }

        return $this;
    }

    public function toMicroseconds(): int
    {
        return $this->microseconds;
    }

    public function toMilliseconds(): int
    {
        return intdiv($this->microseconds, self::UNITS['ms']);
    }

    public function toSeconds(): float
    {
        return $this->microseconds / self::UNITS['s'];
    }

    /** @throws TimeException */
    public function plus(self $other): self
    {
        if ($this->microseconds > \PHP_INT_MAX - $other->microseconds) {
            throw TimeException::durationTooLarge();
        }

        return new self($this->microseconds + $other->microseconds);
    }

    public function isLongerThan(self $other): bool
    {
        return $this->microseconds > $other->microseconds;
    }

    public function equals(self $other): bool
    {
        return $this->microseconds === $other->microseconds;
    }

    public function formatAsClock(): string
    {
        $seconds = intdiv($this->microseconds, self::UNITS['s']);
        $fraction = $this->microseconds % self::UNITS['s'];
        $clock = \sprintf('%02d:%02d:%02d', intdiv($seconds, 3_600), intdiv($seconds % 3_600, 60), $seconds % 60);

        return $fraction === 0 ? $clock : $clock . '.' . rtrim(\sprintf('%06d', $fraction), '0');
    }

    public function __toString(): string
    {
        if ($this->microseconds === 0) {
            return '0s';
        }

        $parts = [];
        $remaining = $this->microseconds;

        foreach (self::UNITS as $unit => $size) {
            $amount = intdiv($remaining, $size);
            $remaining -= $amount * $size;

            if ($amount > 0) {
                $parts[] = $amount . $unit;
            }
        }

        return implode(' ', $parts);
    }

    /** @throws TimeException */
    private static function scale(int|float $amount, int $factor): int
    {
        if (!is_finite((float) $amount)) {
            throw TimeException::durationNotFinite((float) $amount);
        }

        $scaled = round($amount * $factor);

        if (abs($scaled) > \PHP_INT_MAX) {
            throw TimeException::durationTooLarge();
        }

        return (int) $scaled;
    }
}
