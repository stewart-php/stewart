<?php

declare(strict_types=1);

namespace Stewart\Codegen\Tests\Fixtures;

use Psr\Log\LoggerInterface;
use Stewart\Client\Connection\ConnectionConfig;
use Stewart\Codegen\Snapshot\Snapshot;
use Stewart\Codegen\Snapshot\SnapshotFetcher;

final class GoldenSnapshotFetcher implements SnapshotFetcher
{
    public ?string $fetchedFromUrl = null;

    public function fetchSnapshot(ConnectionConfig $connection, LoggerInterface $logger): Snapshot
    {
        $this->fetchedFromUrl = $connection->url->reveal();

        return GoldenSnapshot::loadSnapshot();
    }
}
