<?php

declare(strict_types=1);

namespace Stewart\Contracts\Schedule;

use Cron\CronExpression;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Stewart\Contracts\Exception\ScheduleException;

/** @internal */
final readonly class CronSchedule implements WallClockSchedule
{
    // Bounds expressions no calendar satisfies, such as "0 0 30 2 *".
    private const int MAX_CANDIDATE_EVALUATIONS = 10_000;

    // DST gaps last hours, so a candidate over a day past the first always resolves; stop there.
    private const string DST_RESOLUTION_HORIZON = 'P1D';

    private function __construct(
        public string $expression,
        private CronExpression $parsed,
    ) {}

    /** @throws ScheduleException */
    public static function parse(string $expression): self
    {
        try {
            $parsed = new CronExpression($expression);
        } catch (InvalidArgumentException $e) {
            throw ScheduleException::cronInvalid($expression, $e);
        }

        $parsed->setMaxIterationCount(self::MAX_CANDIDATE_EVALUATIONS);

        return new self($expression, $parsed);
    }

    public function findNextOccurrenceAfter(DateTimeImmutable $after): ?DateTimeImmutable
    {
        $zone = $after->getTimezone();
        // Walk wall time as UTC (no transitions); TimeOfDay::resolveOnDay applies the DST rule.
        $cursor = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $after->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));

        if ($cursor === false) {
            return null;
        }

        $horizon = null;

        do {
            try {
                $occurrence = DateTimeImmutable::createFromInterface($this->parsed->getNextRunDate($cursor, 0, false, 'UTC'));
            } catch (RuntimeException) {
                return null;
            }

            $horizon ??= $occurrence->add(new DateInterval(self::DST_RESOLUTION_HORIZON));
            $candidate = TimeOfDay::fromDateTime($occurrence)->resolveOnDay($occurrence, $zone);

            if ($candidate !== null && $candidate > $after) {
                return $candidate;
            }

            $cursor = $occurrence;
        } while ($cursor <= $horizon);

        return null;
    }

    public function describe(): string
    {
        return \sprintf('cron "%s"', $this->expression);
    }

    public function isRecurring(): bool
    {
        return true;
    }
}
