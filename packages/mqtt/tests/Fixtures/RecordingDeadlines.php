<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Tests\Fixtures;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Future;
use Stewart\Contracts\Time\Duration;
use Stewart\Support\Time\Deadlines;

final class RecordingDeadlines implements Deadlines
{
    /** @var list<Duration> */
    public private(set) array $delays = [];

    /** @var array<int, DeferredFuture<null>> */
    private array $delayCountWaiters = [];

    public function __construct(private readonly Deadlines $inner) {}

    public function timeout(Duration $limit): Cancellation
    {
        return $this->inner->timeout($limit);
    }

    public function delay(Duration $wait, ?Cancellation $cancellation = null): void
    {
        $this->delays[] = $wait;
        $waiter = $this->delayCountWaiters[\count($this->delays)] ?? null;
        unset($this->delayCountWaiters[\count($this->delays)]);
        $waiter?->complete();
        $this->inner->delay($wait, $cancellation);
    }

    /** @return Future<null> */
    public function waitForDelayCount(int $count): Future
    {
        if (\count($this->delays) >= $count) {
            return Future::complete();
        }

        /** @var DeferredFuture<null> $waiter */
        $waiter = $this->delayCountWaiters[$count] ?? new DeferredFuture();
        $this->delayCountWaiters[$count] = $waiter;

        return $waiter->getFuture();
    }

    /** @return list<int> */
    public function listDelayMilliseconds(): array
    {
        return array_map(static fn(Duration $delay): int => $delay->toMilliseconds(), $this->delays);
    }
}
