<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\Exception\RegistryEditException;
use Stewart\Contracts\Registry\RegisteredEntity;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\MonotonicTime;
use Stewart\Runtime\Broker\Exposure\ExposureLink;
use Stewart\Runtime\Config\ServiceCallPolicy;
use Stewart\Runtime\Ipc\Message\RegistryEntityUpdateFailed;
use Stewart\Runtime\Ipc\Message\RegistryEntityUpdateRequest;
use Stewart\Runtime\Ipc\Message\RegistryEntityUpdateResult;
use Stewart\Runtime\Model\ServiceCallOutcome;
use Throwable;

use function Amp\async;

final readonly class RegistryEditProxy
{
    public function __construct(
        private HaSession $session,
        private ExposureLink $exposures,
        private HaCallSlots $slots,
        private AppMetrics $metrics,
        private ServiceCallPolicy $policy,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    public function forward(WorkerHandle $handle, RegistryEntityUpdateRequest $request): void
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
                    $outcome = $this->updateOnHomeAssistant($request);
                } finally {
                    $this->slots->release($handle);
                }

                $handle->send($outcome);
                $this->recordFinishedUpdate($handle, $request, $outcome, $startedAt);
            } catch (Throwable $e) {
                $this->logger->error('Registry update reply failed', [
                    'exception' => $e,
                    'worker' => $handle->id->value,
                    'correlation_id' => $request->correlationId->value,
                ]);
            }
        })->ignore();
    }

    private function updateOnHomeAssistant(RegistryEntityUpdateRequest $request): RegistryEntityUpdateResult|RegistryEntityUpdateFailed
    {
        try {
            if (!$this->session->isConnected()) {
                throw RegistryEditException::unreachable($request->entityId, 'Home Assistant is disconnected');
            }

            $this->warnIfExposed($request);

            return new RegistryEntityUpdateResult($request->correlationId, $this->policy->dryRun ? $this->previewUpdate($request) : $this->sendUpdate($request));
        } catch (RegistryEditException $e) {
            return RegistryEntityUpdateFailed::fromException($request->correlationId, $e);
        } catch (Throwable $e) {
            return RegistryEntityUpdateFailed::fromException(
                $request->correlationId,
                RegistryEditException::unreachable($request->entityId, $e->getMessage(), $e),
            );
        }
    }

    private function warnIfExposed(RegistryEntityUpdateRequest $request): void
    {
        $exposure = $this->exposures->findAddressByEntityId($request->entityId);

        if ($exposure === null) {
            return;
        }

        $this->logger->warning('Editing the registry entry of an entity a Stewart app exposes; its handle owns the name and icon', [
            'entity' => $request->entityId->value,
            'app' => $exposure->appId->value,
            'key' => $exposure->key->value,
        ]);
    }

    /** @throws RegistryEditException */
    private function sendUpdate(RegistryEntityUpdateRequest $request): RegisteredEntity
    {
        $update = $request->update->needsCurrentEntry()
            ? $request->update->resolveAgainst($this->session->getEntityRegistryEntry($request->entityId))
            : $request->update;

        return $this->session->updateEntityRegistryEntry($request->entityId, $update);
    }

    /** @throws RegistryEditException */
    private function previewUpdate(RegistryEntityUpdateRequest $request): RegisteredEntity
    {
        $this->logger->info('Registry entry not updated: service_calls.dry_run is on', ['entity' => $request->entityId->value]);

        return $request->update->applyTo($this->session->getEntityRegistryEntry($request->entityId));
    }

    private function recordFinishedUpdate(
        WorkerHandle $handle,
        RegistryEntityUpdateRequest $request,
        RegistryEntityUpdateResult|RegistryEntityUpdateFailed $outcome,
        MonotonicTime $startedAt,
    ): void {
        $this->metrics->recordFinishedCall(
            $handle->id,
            $request->scope,
            $outcome instanceof RegistryEntityUpdateFailed ? ServiceCallOutcome::failedWith($outcome->getReason()) : ServiceCallOutcome::Succeeded,
            $this->clock->getMonotonicTime()->elapsedSince($startedAt),
        );
    }

    private function refuse(WorkerHandle $handle, RegistryEntityUpdateRequest $request, string $reason): void
    {
        $this->slots->recordRefusal($handle, $request->scope);
        $this->metrics->recordRefusedCall($handle->id, $request->scope);
        $handle->send(RegistryEntityUpdateFailed::fromException(
            $request->correlationId,
            RegistryEditException::overloaded($request->entityId, $reason),
        ));
    }
}
