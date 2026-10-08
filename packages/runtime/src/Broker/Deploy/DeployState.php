<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Deploy;

use Stewart\Contracts\Time\Instant;

final class DeployState
{
    private ?CommitId $runningCommit = null;

    private ?Instant $lastPolledAt = null;

    private ?ReleaseFailure $lastFailure = null;

    private int $failures = 0;

    public function recordRunningCommit(CommitId $commit): void
    {
        $this->runningCommit = $commit;
    }

    public function recordPoll(Instant $polledAt): void
    {
        $this->lastPolledAt = $polledAt;
    }

    public function recordFailure(ReleaseFailure $failure): void
    {
        $this->lastFailure = $failure;
        ++$this->failures;
    }

    public function findRunningCommit(): ?CommitId
    {
        return $this->runningCommit;
    }

    public function findLastPolledAt(): ?Instant
    {
        return $this->lastPolledAt;
    }

    public function findLastFailure(): ?ReleaseFailure
    {
        return $this->lastFailure;
    }

    public function countFailures(): int
    {
        return $this->failures;
    }
}
