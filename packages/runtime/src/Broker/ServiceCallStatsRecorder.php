<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Control\Protocol\Status\LatencyHistogram;
use Stewart\Runtime\Control\Protocol\Status\ServiceCallStats;
use Stewart\Runtime\Model\ServiceCallOutcome;

final class ServiceCallStatsRecorder
{
    // Prometheus' default seconds buckets, in milliseconds.
    private const array BOUNDS_MS = [5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000];

    private int $count = 0;

    /** @var array<int, int> */
    private array $bucketCounts = [];

    private int $timed = 0;

    private int $sumMicroseconds = 0;

    public function __construct(private readonly ServiceCallOutcome $outcome) {}

    public function recordCall(?Duration $latency): void
    {
        ++$this->count;

        if ($latency === null) {
            return;
        }

        ++$this->timed;
        $this->sumMicroseconds += $latency->toMicroseconds();

        foreach (self::BOUNDS_MS as $index => $bound) {
            if ($latency->toMicroseconds() <= $bound * 1000) {
                $this->bucketCounts[$index] = ($this->bucketCounts[$index] ?? 0) + 1;

                return;
            }
        }
    }

    public function buildServiceCallStats(): ServiceCallStats
    {
        return new ServiceCallStats($this->outcome, $this->count, $this->timed === 0 ? null : $this->buildLatencyHistogram());
    }

    private function buildLatencyHistogram(): LatencyHistogram
    {
        $cumulative = [];
        $running = 0;

        foreach (array_keys(self::BOUNDS_MS) as $index) {
            $running += $this->bucketCounts[$index] ?? 0;
            $cumulative[] = $running;
        }

        return new LatencyHistogram(self::BOUNDS_MS, $cumulative, $this->timed, Duration::microseconds($this->sumMicroseconds));
    }
}
