<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Exposure;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Message\ExposuresReleased;
use Stewart\Runtime\Ipc\Transport;
use Stewart\Runtime\Model\ResourceScope;

final class ExposedHandleRegistry
{
    /** @var array<string, array<string, ExposedHandle>> */
    private array $handlesByScope = [];

    public function __construct(
        private readonly Transport $transport,
        private readonly LoggerInterface $logger,
    ) {}

    public function isKeyTaken(ResourceScope $scope, ExposedEntityKey $key): bool
    {
        return isset($this->handlesByScope[$scope->wireValue()][$key->value]);
    }

    public function recordHandle(ResourceScope $scope, ExposedEntityKey $key, ExposedHandle $handle): void
    {
        $this->handlesByScope[$scope->wireValue()][$key->value] = $handle;
    }

    public function forgetHandle(ResourceScope $scope, ExposedEntityKey $key): void
    {
        unset($this->handlesByScope[$scope->wireValue()][$key->value]);
    }

    public function findHandle(ResourceScope $scope, ExposedEntityKey $key): ?ExposedHandle
    {
        return $this->handlesByScope[$scope->wireValue()][$key->value] ?? null;
    }

    public function applySnapshot(ResourceScope $scope, ExposedEntityKey $key, ExposedEntitySnapshot $snapshot): void
    {
        $this->findHandle($scope, $key)?->applySnapshot($snapshot);
    }

    public function releaseHandlesOf(ResourceScope $scope): void
    {
        $handles = $this->handlesByScope[$scope->wireValue()] ?? [];
        unset($this->handlesByScope[$scope->wireValue()]);

        if ($handles === []) {
            return;
        }

        foreach ($handles as $handle) {
            $handle->markReleased();
        }

        try {
            $this->transport->send(new ExposuresReleased($scope));
        } catch (TransportException $e) {
            $this->logger->debug('Could not release exposed entities; the broker channel is closed', ['exception' => $e, 'scope' => (string) $scope]);
        }
    }

    public function releaseAll(): void
    {
        foreach ($this->handlesByScope as $handles) {
            foreach ($handles as $handle) {
                $handle->markReleased();
            }
        }

        $this->handlesByScope = [];
    }
}
