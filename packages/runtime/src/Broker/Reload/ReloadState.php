<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Reload;

use Stewart\Runtime\Lifecycle\ReloadOutcome;

final class ReloadState
{
    private bool $underway = false;

    private ?ReloadResult $lastResult = null;

    /** @var array<string, int> */
    private array $resultCounts = [];

    public function beginReload(): void
    {
        $this->underway = true;
    }

    public function abandonReload(): void
    {
        $this->underway = false;
    }

    public function recordResult(ReloadResult $result): void
    {
        $this->underway = false;
        $this->lastResult = $result;
        $this->resultCounts[$result->outcome->value] = $this->countResults($result->outcome) + 1;
    }

    public function isUnderway(): bool
    {
        return $this->underway;
    }

    public function findLastResult(): ?ReloadResult
    {
        return $this->lastResult;
    }

    public function countResults(ReloadOutcome $outcome): int
    {
        return $this->resultCounts[$outcome->value] ?? 0;
    }
}
