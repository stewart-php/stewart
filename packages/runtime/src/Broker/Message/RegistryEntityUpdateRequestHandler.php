<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Message;

use Psr\Log\LoggerInterface;
use Stewart\Client\HaClient;
use Stewart\Contracts\Exception\RegistryEditException;
use Stewart\Contracts\Registry\RegisteredEntity;
use Stewart\Runtime\Broker\Exposure\ExposureLink;
use Stewart\Runtime\Broker\HaCallSlots;
use Stewart\Runtime\Broker\HaSession;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Config\ServiceCallPolicy;
use Stewart\Runtime\Ipc\Message\RegistryEntityUpdateRequest;
use Stewart\Runtime\Ipc\Message\RegistryEntityUpdateResult;
use Stewart\Runtime\Ipc\Message\WorkerMessage;

/** @implements WorkerMessageHandler<RegistryEntityUpdateRequest> */
final readonly class RegistryEntityUpdateRequestHandler implements WorkerMessageHandler
{
    public function __construct(
        private HaSession $session,
        private HaClient $client,
        private ExposureLink $exposures,
        private HaCallSlots $slots,
        private ServiceCallPolicy $policy,
        private LoggerInterface $logger,
    ) {}

    public function handledMessageClass(): string
    {
        return RegistryEntityUpdateRequest::class;
    }

    /** @param RegistryEntityUpdateRequest $message */
    public function handle(WorkerHandle $handle, WorkerMessage $message): void
    {
        $this->slots->runBounded($handle, $message, fn(): RegistryEntityUpdateResult => $this->updateOnHomeAssistant($message));
    }

    /** @throws RegistryEditException */
    private function updateOnHomeAssistant(RegistryEntityUpdateRequest $request): RegistryEntityUpdateResult
    {
        if (!$this->session->isConnected()) {
            throw $request->createUnreachableFailure('Home Assistant is disconnected');
        }

        $this->warnIfExposed($request);

        return new RegistryEntityUpdateResult($request->correlationId, $this->policy->dryRun ? $this->previewUpdate($request) : $this->sendUpdate($request));
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
            ? $request->update->resolveAgainst($this->client->getEntityRegistryEntry($request->entityId)->toRegisteredEntity())
            : $request->update;

        return $this->client->updateEntityRegistryEntry($request->entityId, $update)->toRegisteredEntity();
    }

    /** @throws RegistryEditException */
    private function previewUpdate(RegistryEntityUpdateRequest $request): RegisteredEntity
    {
        $this->logger->info('Registry entry not updated: service_calls.dry_run is on', ['entity' => $request->entityId->value]);

        return $request->update->applyTo($this->client->getEntityRegistryEntry($request->entityId)->toRegisteredEntity());
    }
}
