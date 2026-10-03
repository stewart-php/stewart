<?php

declare(strict_types=1);

namespace Stewart\Codegen\Snapshot;

use Psr\Log\LoggerInterface;
use Stewart\Client\Connection\ConnectionConfig;
use Stewart\Client\Exception\HaClientException;
use Stewart\Codegen\Exception\CodegenException;

interface SnapshotFetcher
{
    /**
     * @throws HaClientException
     * @throws CodegenException
     */
    public function fetchSnapshot(ConnectionConfig $connection, LoggerInterface $logger): Snapshot;
}
