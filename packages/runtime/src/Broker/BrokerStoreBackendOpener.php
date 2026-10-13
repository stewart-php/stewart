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
        private PersistenceConfig $persistenceConfig,
    ) {}

    /**
     * @throws StoreException
     * @throws StoreSetupException
     */
    public function openConfiguredBackend(): ?GuardedStoreBackend
    {
        $url = $this->persistenceConfig->url;

        if ($url === null) {
            return null;
        }

        $this->logger->debug('Apps will store to ' . $url, ['prefix' => $this->persistenceConfig->prefix->value]);

        return $this->storeBackendConnector->connectToBackend(
            $url,
            new StoreTiming($this->persistenceConfig->timeout, $this->persistenceConfig->recoveryInterval),
        );
    }
}
