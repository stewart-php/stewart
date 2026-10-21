<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console;

use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\CancelledException;
use Closure;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Exception\ControlError;
use Stewart\Runtime\Exception\ControlException;
use Stewart\Support\Time\Deadlines;

final readonly class StatusWatch
{
    // A daemon that is down or restarting comes back; a wrong token, address or protocol does not.
    private const array PASSING_FAILURES = [ControlError::BrokerHungUp, ControlError::RequestTimedOut];

    public function __construct(private Deadlines $deadlines) {}

    /**
     * @param Closure(): RuntimeSnapshot $fetchSnapshot
     * @param Closure(RuntimeSnapshot): void $drawFrame
     * @param Closure(string): void $drawUnreachable
     * @throws StewartException
     */
    public function watchStatus(Closure $fetchSnapshot, Closure $drawFrame, Closure $drawUnreachable, Duration $interval, Cancellation $stop): void
    {
        while (!$stop->isRequested()) {
            try {
                $drawFrame($fetchSnapshot());
            } catch (StreamException|CancelledException $e) {
                $drawUnreachable($e->getMessage());
            } catch (ControlException $e) {
                if (!\in_array($e->reason, self::PASSING_FAILURES, true)) {
                    throw $e;
                }

                $drawUnreachable($e->getMessage());
            }

            try {
                $this->deadlines->delay($interval, $stop);
            } catch (CancelledException) {
                return;
            }
        }
    }
}
