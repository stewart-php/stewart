<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\Exception\StoreException;
use Stewart\Runtime\Config\PersistenceConfig;
use Stewart\Store\Exception\StoreSetupException;
use Stewart\Store\GuardedStoreBackend;
use Stewart\Store\StoreBackendConnector;
use Stewart\Store\StoreTiming;

final readonly class BrokerStoreBackendOpener
{
    public function __construct(
        private StoreBackendConnector $storeBackendConnector,
        private LoggerInterface $logger,
        private ?PersistenceConfig $persistenceConfig = null,
    ) {}

    /**
     * @throws StoreException
     * @throws StoreSetupException
     */
    public function openConfiguredBackend(): ?GuardedStoreBackend
    {
        $persistenceConfig = $this->persistenceConfig;

        if ($persistenceConfig === null) {
            return null;
        }

        $this->logger->debug('Apps will store to ' . $persistenceConfig->url, ['prefix' => $persistenceConfig->prefix->value]);

        return $this->storeBackendConnector->connectToBackend(
            $persistenceConfig->url,
            new StoreTiming($persistenceConfig->timeout, $persistenceConfig->recoveryInterval),
        );
    }
}
