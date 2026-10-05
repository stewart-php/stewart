<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\Exception\ServiceCallException;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Contracts\State\EventContext;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\MonotonicTime;
use Stewart\Runtime\Config\ServiceCallPolicy;
use Stewart\Runtime\Ipc\Message\ServiceCallFailed;
use Stewart\Runtime\Ipc\Message\ServiceCallRequest;
use Stewart\Runtime\Ipc\Message\ServiceCallResult;
use Stewart\Runtime\Logging\EveryNthOccurrence;
use Stewart\Runtime\Model\ServiceCallOutcome;
use Throwable;
use WeakMap;

use function Amp\async;

final class ServiceCallProxy
{
    private const string DRY_RUN_CONTEXT_PREFIX = 'dry-run:';

    /** @var WeakMap<WorkerHandle, int> */
    private WeakMap $perWorker;

    public private(set) int $inFlight = 0;

    public private(set) int $refusedCalls = 0;

    private readonly EveryNthOccurrence $refusalReports;

    public function __construct(
        private readonly HaSession $session,
        private readonly AppMetrics $metrics,
        private readonly ServiceCallPolicy $policy,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
        $this->perWorker = new WeakMap();
        $this->refusalReports = EveryNthOccurrence::forRepeatedWarnings();
    }

    public function forward(WorkerHandle $handle, ServiceCallRequest $request): void
    {
        $refusal = $this->findRefusalReason($handle);

        if ($refusal !== null) {
            $this->refuse($handle, $request, $refusal);

            return;
        }

        ++$this->inFlight;
        $this->perWorker[$handle] = $this->countInFlightCallsFor($handle) + 1;
        $startedAt = $this->clock->getMonotonicTime();

        async(function () use ($handle, $request, $startedAt): void {
            try {
                try {
                    $outcome = $this->callHomeAssistant($request);
                } finally {
                    $this->release($handle);
                }

                $handle->send($outcome);
                $this->recordFinishedCall($handle, $request, $outcome, $startedAt);
            } catch (Throwable $e) {
                $this->logger->error('Service call reply failed', [
                    'exception' => $e,
                    'worker' => $handle->id->value,
                    'correlation_id' => $request->correlationId->value,
                ]);
            }
        })->ignore();
    }

    private function callHomeAssistant(ServiceCallRequest $request): ServiceCallResult|ServiceCallFailed
    {
        if ($this->policy->dryRun) {
            $this->logger->info('Service call not sent: service_calls.dry_run is on', [
                'domain' => $request->domain,
                'service' => $request->service,
                'target' => $request->target,
                'data' => $request->data,
            ]);

            return new ServiceCallResult(
                $request->correlationId,
                new ServiceResponse($request->domain, $request->service, context: new EventContext(self::DRY_RUN_CONTEXT_PREFIX . $request->correlationId->value)),
            );
        }

        try {
            if (!$this->session->isConnected()) {
                throw ServiceCallException::unreachable($request->domain, $request->service, 'Home Assistant is disconnected');
            }

            return new ServiceCallResult($request->correlationId, $this->session->callService(
                $request->domain,
                $request->service,
                $request->data,
                $request->target,
                $request->returnResponse,
            ));
        } catch (ServiceCallException $e) {
            return ServiceCallFailed::fromException($request->correlationId, $e);
        } catch (Throwable $e) {
            return ServiceCallFailed::fromException(
                $request->correlationId,
                ServiceCallException::unreachable($request->domain, $request->service, $e->getMessage(), $e),
            );
        }
    }

    private function recordFinishedCall(
        WorkerHandle $handle,
        ServiceCallRequest $request,
        ServiceCallResult|ServiceCallFailed $outcome,
        MonotonicTime $startedAt,
    ): void {
        $this->metrics->recordFinishedCall(
            $handle->id,
            $request->scope,
            $outcome instanceof ServiceCallFailed ? ServiceCallOutcome::failedWith($outcome->getReason()) : ServiceCallOutcome::Succeeded,
            $this->clock->getMonotonicTime()->elapsedSince($startedAt),
        );
    }

    public function countInFlightCallsFor(WorkerHandle $handle): int
    {
        return $this->perWorker[$handle] ?? 0;
    }

    private function findRefusalReason(WorkerHandle $handle): ?string
    {
        if ($this->policy->boundsTotal() && $this->inFlight >= $this->policy->total) {
            return \sprintf('the broker already has %d service calls in flight', $this->policy->total);
        }

        if ($this->policy->limitsPerWorker() && $this->countInFlightCallsFor($handle) >= $this->policy->perWorker) {
            return \sprintf('this worker already has %d service calls in flight', $this->policy->perWorker);
        }

        return null;
    }

    private function refuse(WorkerHandle $handle, ServiceCallRequest $request, string $reason): void
    {
        ++$this->refusedCalls;

        $refusal = ServiceCallFailed::fromException(
            $request->correlationId,
            ServiceCallException::overloaded($request->domain, $request->service, $reason),
        );

        $this->metrics->recordRefusedCall($handle->id, $request->scope);
        $handle->send($refusal);

        if ($this->refusalReports->includesOccurrence($this->refusedCalls)) {
            $this->logger->warning('Refusing service calls; Home Assistant is not keeping up', [
                'worker' => $handle->id->value,
                'app' => $request->scope->wireValue(),
                'refused' => $this->refusedCalls,
                'in_flight' => $this->inFlight,
            ]);
        }
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
