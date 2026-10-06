<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\Exception\ServiceCallException;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\MonotonicTime;
use Stewart\Runtime\Config\ServiceCallPolicy;
use Stewart\Runtime\Ipc\Message\ServiceCallFailed;
use Stewart\Runtime\Ipc\Message\ServiceCallRequest;
use Stewart\Runtime\Ipc\Message\ServiceCallResult;
use Stewart\Runtime\Model\ServiceCallOutcome;
use Throwable;

use function Amp\async;

final readonly class ServiceCallProxy
{
    public function __construct(
        private HaSession $session,
        private HaCallSlots $slots,
        private AppMetrics $metrics,
        private ServiceCallPolicy $policy,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    public function forward(WorkerHandle $handle, ServiceCallRequest $request): void
    {
        $refusal = $this->slots->findRefusalReason($handle);

        if ($refusal !== null) {
            $this->refuse($handle, $request, $refusal);

            return;
        }

        $this->slots->acquire($handle);
        $startedAt = $this->clock->getMonotonicTime();

        async(function () use ($handle, $request, $startedAt): void {
            try {
                try {
                    $outcome = $this->callHomeAssistant($request);
                } finally {
                    $this->slots->release($handle);
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
                new ServiceResponse($request->domain, $request->service, context: $this->policy->createDryRunContext($request->correlationId)),
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

    private function refuse(WorkerHandle $handle, ServiceCallRequest $request, string $reason): void
    {
        $this->slots->recordRefusal($handle, $request->scope);
        $this->metrics->recordRefusedCall($handle->id, $request->scope);
        $handle->send(ServiceCallFailed::fromException(
            $request->correlationId,
            ServiceCallException::overloaded($request->domain, $request->service, $reason),
        ));
    }
}
