<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\Event\EventPayload;
use Stewart\Contracts\Exception\EventFireError;
use Stewart\Contracts\Exception\EventFireException;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\MonotonicTime;
use Stewart\Runtime\Config\ServiceCallPolicy;
use Stewart\Runtime\Ipc\Message\EventFireRequest;
use Stewart\Runtime\Ipc\Message\EventFireResult;
use Stewart\Runtime\Ipc\Message\RequestFailed;
use Stewart\Runtime\Model\ServiceCallOutcome;
use Throwable;

use function Amp\async;

final readonly class EventFireProxy
{
    public function __construct(
        private HaSession $session,
        private HaCallSlots $slots,
        private AppMetrics $metrics,
        private ServiceCallPolicy $policy,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    public function forward(WorkerHandle $handle, EventFireRequest $request): void
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
                    $outcome = $this->fireOnHomeAssistant($request);
                } finally {
                    $this->slots->release($handle);
                }

                $handle->send($outcome instanceof EventFireException ? RequestFailed::fromException($request->correlationId, $outcome) : $outcome);
                $this->recordFinishedFire($handle, $request, $outcome, $startedAt);
            } catch (Throwable $e) {
                $this->logger->error('Event fire reply failed', [
                    'exception' => $e,
                    'worker' => $handle->id->value,
                    'correlation_id' => $request->correlationId->value,
                ]);
            }
        })->ignore();
    }

    private function fireOnHomeAssistant(EventFireRequest $request): EventFireResult|EventFireException
    {
        if ($this->policy->dryRun) {
            $this->logger->info('Event not fired: service_calls.dry_run is on', [
                'event_type' => $request->eventType,
                'data' => $request->data,
            ]);

            return new EventFireResult($request->correlationId, $this->policy->createDryRunContext($request->correlationId));
        }

        try {
            if (!$this->session->isConnected()) {
                throw EventFireException::unreachable($request->eventType, 'Home Assistant is disconnected');
            }

            return new EventFireResult($request->correlationId, $this->session->fireEvent(new EventPayload($request->eventType, $request->data)));
        } catch (EventFireException $e) {
            return $e;
        } catch (Throwable $e) {
            return EventFireException::unreachable($request->eventType, $e->getMessage(), $e);
        }
    }

    private function recordFinishedFire(
        WorkerHandle $handle,
        EventFireRequest $request,
        EventFireResult|EventFireException $outcome,
        MonotonicTime $startedAt,
    ): void {
        $this->metrics->recordFinishedCall(
            $handle->id,
            $request->scope,
            $outcome instanceof EventFireException ? ServiceCallOutcome::failedWith($outcome->reason) : ServiceCallOutcome::Succeeded,
            $this->clock->getMonotonicTime()->elapsedSince($startedAt),
        );
    }

    private function refuse(WorkerHandle $handle, EventFireRequest $request, string $reason): void
    {
        $this->slots->recordRefusal($handle, $request->scope);
        $this->metrics->recordRefusedCall($handle->id, $request->scope);
        $handle->send(RequestFailed::fromException(
            $request->correlationId,
            EventFireException::createForReason(EventFireError::Overloaded, eventType: $request->eventType, detail: $reason),
        ));
    }
}
