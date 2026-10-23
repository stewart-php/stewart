<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Closure;
use Psr\Log\LoggerInterface;
use Stewart\Contracts\Exception\ExceptionReason;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Contracts\Time\Clock;
use Stewart\Runtime\Config\HaCallPolicy;
use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\HaCallRequest;
use Stewart\Runtime\Ipc\Message\RefusableHaCallRequest;
use Stewart\Runtime\Ipc\Message\RequestFailed;
use Stewart\Runtime\Logging\EveryNthOccurrence;
use Stewart\Runtime\Logging\LogOrigin;
use Stewart\Runtime\Model\HaCallOutcome;
use Throwable;
use WeakMap;

use function Amp\async;

final class HaCallSlots
{
    /** @var WeakMap<WorkerHandle, int> */
    private WeakMap $perWorker;

    public private(set) int $inFlight = 0;

    public private(set) int $refusedCalls = 0;

    private readonly EveryNthOccurrence $refusalReports;

    public function __construct(
        private readonly HaCallPolicy $policy,
        private readonly AppMetrics $metrics,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
        $this->perWorker = new WeakMap();
        $this->refusalReports = EveryNthOccurrence::forRepeatedWarnings();
    }

    /** @param Closure(): BrokerMessage $call */
    public function runBounded(WorkerHandle $handle, RefusableHaCallRequest $request, Closure $call, bool $metered = true): void
    {
        $refusal = $this->findRefusalReason($handle);

        if ($refusal !== null) {
            $this->refuse($handle, $request, $refusal, $metered);

            return;
        }

        $this->runInSlot($handle, $request, $call, $metered);
    }

    // Exposure updates hold a slot but are never refused, so HA never keeps a stale exposed state.
    /** @param Closure(): BrokerMessage $call */
    public function runCounted(WorkerHandle $handle, HaCallRequest $request, Closure $call): void
    {
        $this->runInSlot($handle, $request, $call, metered: false);
    }

    public function countInFlightCallsFor(WorkerHandle $handle): int
    {
        return $this->perWorker[$handle] ?? 0;
    }

    /** @param Closure(): BrokerMessage $call */
    private function runInSlot(WorkerHandle $handle, HaCallRequest $request, Closure $call, bool $metered): void
    {
        $this->acquire($handle);
        $startedAt = $this->clock->getMonotonicTime();

        async(function () use ($handle, $request, $call, $metered, $startedAt): void {
            try {
                try {
                    $reply = $call();
                    $outcome = HaCallOutcome::Succeeded;
                } catch (Throwable $e) {
                    $failure = $this->describeFailure($request, $e);
                    $reply = RequestFailed::fromException($request->correlationId, $failure);
                    $outcome = HaCallOutcome::failedWith($failure->reason);
                } finally {
                    $this->release($handle);
                }

                $handle->send($reply);

                if ($metered) {
                    $this->metrics->recordFinishedCall($handle->id, $request->scope, $outcome, $this->clock->getMonotonicTime()->elapsedSince($startedAt));
                }
            } catch (Throwable $e) {
                $this->logger->error('Home Assistant call reply failed', [
                    'exception' => $e,
                    'worker' => $handle->id->value,
                    'correlation_id' => $request->correlationId->value,
                ]);
            }
        })->ignore();
    }

    // A failure of the request's own kind passes through; anything else means Home Assistant was not reached.
    /** @return StewartException<ExceptionReason> */
    private function describeFailure(HaCallRequest $request, Throwable $e): StewartException
    {
        $unreachable = $request->createUnreachableFailure($e->getMessage(), $e);

        return $e instanceof $unreachable ? $e : $unreachable;
    }

    private function findRefusalReason(WorkerHandle $handle): ?string
    {
        if ($this->policy->boundsTotal() && $this->inFlight >= $this->policy->total) {
            return \sprintf('the broker already has %d calls to Home Assistant in flight', $this->policy->total);
        }

        if ($this->policy->limitsPerWorker() && $this->countInFlightCallsFor($handle) >= $this->policy->perWorker) {
            return \sprintf('this worker already has %d calls to Home Assistant in flight', $this->policy->perWorker);
        }

        return null;
    }

    private function refuse(WorkerHandle $handle, RefusableHaCallRequest $request, string $reason, bool $metered): void
    {
        ++$this->refusedCalls;

        if ($this->refusalReports->includesOccurrence($this->refusedCalls)) {
            $this->logger->warning('Refusing calls; Home Assistant is not keeping up', [
                'worker' => $handle->id->value,
                'app' => $request->scope->wireValue(),
                'refused' => $this->refusedCalls,
                'in_flight' => $this->inFlight,
                LogOrigin::CONTEXT_KEY => LogOrigin::forScope($request->scope),
            ]);
        }

        if ($metered) {
            $this->metrics->recordRefusedCall($handle->id, $request->scope);
        }

        $handle->send(RequestFailed::fromException($request->correlationId, $request->createOverloadedFailure($reason)));
    }

    private function acquire(WorkerHandle $handle): void
    {
        ++$this->inFlight;
        $this->perWorker[$handle] = $this->countInFlightCallsFor($handle) + 1;
    }

    private function release(WorkerHandle $handle): void
    {
        --$this->inFlight;

        $forWorker = $this->countInFlightCallsFor($handle) - 1;

        if ($forWorker <= 0) {
            unset($this->perWorker[$handle]);

            return;
        }

        $this->perWorker[$handle] = $forWorker;
    }
}
