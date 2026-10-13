<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Registry;

use Amp\CancelledException;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\RegistryEditException;
use Stewart\Contracts\Registry\RegisteredEntity;
use Stewart\Contracts\Registry\Update\EntityRegistryUpdate;
use Stewart\Runtime\Exception\TransportError;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Message\RegistryEntityUpdateRequest;
use Stewart\Runtime\Ipc\Transport;
use Stewart\Runtime\Ipc\WorkerSettings;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Worker\ConnectionStatus;
use Stewart\Runtime\Worker\PendingRequests;
use Stewart\Support\Time\Deadlines;
use Throwable;

final readonly class RegistryEditRequester
{
    public function __construct(
        private Transport $transport,
        private PendingRequests $pending,
        private ConnectionStatus $connection,
        private Deadlines $deadlines,
        private WorkerSettings $workerSettings,
    ) {}

    /** @throws RegistryEditException */
    public function requestEntityUpdate(ResourceScope $scope, EntityId $entityId, EntityRegistryUpdate $update): RegisteredEntity
    {
        if (!$this->connection->connected) {
            throw RegistryEditException::unreachable($entityId, 'Home Assistant is disconnected');
        }

        $edit = $this->pending->open(
            RegisteredEntity::class,
            static fn(string $detail): RegistryEditException => RegistryEditException::unreachable($entityId, $detail),
        );

        try {
            $this->sendRequest(new RegistryEntityUpdateRequest($edit->correlationId, $scope, $entityId, $update));

            return $edit->getFuture()->await($this->deadlines->timeout($this->workerSettings->callTimeout));
        } catch (CancelledException $e) {
            throw RegistryEditException::timedOut($entityId, \sprintf('no answer from the broker within %s', $this->workerSettings->callTimeout), $e);
        } finally {
            $this->pending->forget($edit->correlationId);
        }
    }

    /** @throws RegistryEditException */
    private function sendRequest(RegistryEntityUpdateRequest $request): void
    {
        try {
            $this->transport->send($request);
        } catch (Throwable $e) {
            throw $e instanceof TransportException && $e->reason === TransportError::Unencodable
                ? RegistryEditException::rejected($request->entityId, $e->getMessage(), null, $e)
                : RegistryEditException::unreachable($request->entityId, 'the broker is gone: ' . $e->getMessage(), $e);
        }
    }
}
