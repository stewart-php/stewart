<?php

declare(strict_types=1);

namespace Stewart\Codegen\Snapshot;

use Psr\Log\LoggerInterface;
use Stewart\Client\Connection\ConnectionConfig;
use Stewart\Client\HaClientFactory;
use Stewart\Client\HaConfig;
use Stewart\Client\HaCoreState;
use Stewart\Codegen\Exception\CodegenException;

final readonly class HaClientSnapshotFetcher implements SnapshotFetcher
{
    public function __construct(private HaClientFactory $haClientFactory) {}

    public function fetchSnapshot(ConnectionConfig $connection, LoggerInterface $logger): Snapshot
    {
        $client = $this->haClientFactory->createClient($connection, $logger);
        $client->connect();

        try {
            $this->refuseUnlessRunning($client->getConfig());

            return Snapshot::fromParts(
                haVersion: $client->getHaVersion() ?? 'unknown',
                states: $client->getStates(),
                registry: $client->getEntityRegistry(),
                services: $client->getServices(),
                areas: $client->getAreaRegistry(),
                floors: $client->getFloorRegistry(),
                labels: $client->getLabelRegistry(),
            );
        } finally {
            $client->close();
        }
    }

    /** @throws CodegenException */
    private function refuseUnlessRunning(HaConfig $config): void
    {
        $state = $config->coreState;

        if ($state !== null && $state !== HaCoreState::Running) {
            throw CodegenException::homeAssistantNotRunning($state);
        }
    }
}
