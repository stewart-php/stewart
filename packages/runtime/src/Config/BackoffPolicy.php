<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Stewart\Contracts\Time\Duration;

final readonly class BackoffPolicy
{
    private const int MAX_DOUBLINGS = 40;

    public function __construct(
        private Duration $initialDelay,
        private Duration $maxDelay,
    ) {}

    public function delayFor(int $attempt): Duration
    {
        if ($attempt < 1) {
            return Duration::zero();
        }

        $doubled = $this->initialDelay->toMicroseconds() * 2 ** min($attempt - 1, self::MAX_DOUBLINGS);

        return Duration::microseconds((int) min($doubled, $this->maxDelay->toMicroseconds()));
    }

    public function sumDelaysUpTo(int $attempt): Duration
    {
        $total = 0;
        $next = 1;

        while ($next <= $attempt) {
            $delay = $this->delayFor($next)->toMicroseconds();
            $repeats = $this->isDelayFinalAt($next) ? $attempt - $next + 1 : 1;

            if ($delay > 0 && $repeats > intdiv(\PHP_INT_MAX - $total, $delay)) {
                return Duration::microseconds(\PHP_INT_MAX);
            }

            $total += $delay * $repeats;
            $next += $repeats;
        }

        return Duration::microseconds($total);
    }

    private function isDelayFinalAt(int $attempt): bool
    {
        return $attempt > self::MAX_DOUBLINGS || !$this->maxDelay->isLongerThan($this->delayFor($attempt));
    }
}
