<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Psr\Log\LoggerInterface;
use Stewart\Runtime\Config\ServiceCallPolicy;
use Stewart\Runtime\Logging\EveryNthOccurrence;
use Stewart\Runtime\Model\ResourceScope;
use WeakMap;

final class HaCallSlots
{
    /** @var WeakMap<WorkerHandle, int> */
    private WeakMap $perWorker;

    public private(set) int $inFlight = 0;

    public private(set) int $refusedCalls = 0;

    private readonly EveryNthOccurrence $refusalReports;

    public function __construct(
        private readonly ServiceCallPolicy $policy,
        private readonly LoggerInterface $logger,
    ) {
        $this->perWorker = new WeakMap();
        $this->refusalReports = EveryNthOccurrence::forRepeatedWarnings();
    }

    public function findRefusalReason(WorkerHandle $handle): ?string
    {
        if ($this->policy->boundsTotal() && $this->inFlight >= $this->policy->total) {
            return \sprintf('the broker already has %d calls to Home Assistant in flight', $this->policy->total);
        }

        if ($this->policy->limitsPerWorker() && $this->countInFlightCallsFor($handle) >= $this->policy->perWorker) {
            return \sprintf('this worker already has %d calls to Home Assistant in flight', $this->policy->perWorker);
        }

        return null;
    }

    public function acquire(WorkerHandle $handle): void
    {
        ++$this->inFlight;
        $this->perWorker[$handle] = $this->countInFlightCallsFor($handle) + 1;
    }

    public function release(WorkerHandle $handle): void
    {
        --$this->inFlight;

        $forWorker = $this->countInFlightCallsFor($handle) - 1;

        if ($forWorker <= 0) {
            unset($this->perWorker[$handle]);

            return;
        }

        $this->perWorker[$handle] = $forWorker;
    }

    public function recordRefusal(WorkerHandle $handle, ResourceScope $scope): void
    {
        ++$this->refusedCalls;

        if ($this->refusalReports->includesOccurrence($this->refusedCalls)) {
            $this->logger->warning('Refusing calls; Home Assistant is not keeping up', [
                'worker' => $handle->id->value,
                'app' => $scope->wireValue(),
                'refused' => $this->refusedCalls,
                'in_flight' => $this->inFlight,
            ]);
        }
    }

    public function countInFlightCallsFor(WorkerHandle $handle): int
    {
        return $this->perWorker[$handle] ?? 0;
    }
}
