<?php

declare(strict_types=1);

namespace Stewart\Testing\Time;

use Closure;
use PHPUnit\Framework\AssertionFailedError;

use function Amp\delay;

final class EventLoopTicks
{
    private const int DEFAULT_TICK_LIMIT = 50;

    public static function settle(int $ticks = 2): void
    {
        for ($tick = 0; $tick < $ticks; ++$tick) {
            delay(0);
        }
    }

    /** @param Closure(): bool $condition */
    public static function settleUntil(Closure $condition, int $maxTicks = self::DEFAULT_TICK_LIMIT): void
    {
        for ($tick = 0; $tick < $maxTicks; ++$tick) {
            if ($condition()) {
                return;
            }

            delay(0);
        }

        if (!$condition()) {
            throw new AssertionFailedError(\sprintf('The condition still fails after %d event loop ticks.', $maxTicks));
        }
    }
}
