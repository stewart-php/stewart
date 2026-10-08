<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Assembler;

use Stewart\Runtime\Broker\Deploy\DeployState;
use Stewart\Runtime\Control\Protocol\Status\DeployFailure;
use Stewart\Runtime\Control\Protocol\Status\DeployStatus;

final readonly class DeployStatusBuilder
{
    public function __construct(private DeployState $state) {}

    public function buildDeployStatus(): ?DeployStatus
    {
        $commit = $this->state->findRunningCommit();

        if ($commit === null) {
            return null;
        }

        $failure = $this->state->findLastFailure();

        return new DeployStatus(
            commit: $commit->value,
            lastPolledAt: $this->state->findLastPolledAt(),
            failures: $this->state->countFailures(),
            lastFailure: $failure === null ? null : new DeployFailure($failure->commit->value, $failure->reason, $failure->failedAt),
        );
    }
}
