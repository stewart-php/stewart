<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\MonotonicTime;
use Stewart\Runtime\Model\WorkerId;

final class RestartBudget
{
    /** @var array<int, list<MonotonicTime>> */
    private array $restarts = [];

    public function __construct(
        private readonly int $workerRestartAttempts,
        private readonly Duration $workerRestartWindow,
    ) {}

    public function countUsedAttempts(WorkerId $workerId, MonotonicTime $now): int
    {
        return \count($this->listAttemptsWithinWindow($workerId, $now));
    }

    public function claimAttempt(WorkerId $workerId, MonotonicTime $now): ?int
    {
        $spent = $this->listAttemptsWithinWindow($workerId, $now);

        if (\count($spent) >= $this->workerRestartAttempts) {
            $this->restarts[$workerId->value] = $spent;

            return null;
        }

        $spent[] = $now;
        $this->restarts[$workerId->value] = $spent;

        return \count($spent);
    }

    /** @return list<MonotonicTime> */
    private function listAttemptsWithinWindow(WorkerId $workerId, MonotonicTime $now): array
    {
        return array_values(array_filter(
            $this->restarts[$workerId->value] ?? [],
            fn(MonotonicTime $at): bool => $this->workerRestartWindow->isLongerThan($now->elapsedSince($at)),
        ));
    }
}
