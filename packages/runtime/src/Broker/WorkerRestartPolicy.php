<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Contracts\Time\Clock;
use Stewart\Runtime\Config\SupervisionConfig;
use Stewart\Runtime\Model\WorkerId;

final readonly class WorkerRestartPolicy
{
    public function __construct(
        private RestartBudget $budget,
        private SupervisionConfig $supervision,
        private Clock $clock,
    ) {}

    public function decideRestart(WorkerId $workerId): RestartDecision
    {
        $attempt = $this->budget->claimAttempt($workerId, $this->clock->getMonotonicTime());

        return $attempt === null
            ? RestartDecision::quarantine()
            : RestartDecision::restartAfter($this->supervision->restartBackoff->delayFor($attempt));
    }

    public function countRestartsInWindow(WorkerId $workerId): int
    {
        return $this->budget->countUsedAttempts($workerId, $this->clock->getMonotonicTime());
    }
}
