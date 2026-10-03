<?php

declare(strict_types=1);

namespace Stewart\Codegen\Snapshot;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stewart\Codegen\Exception\CodegenException;

final readonly class SnapshotFileReader
{
    public function __construct(private SnapshotCodec $codec) {}

    /** @throws CodegenException */
    public function readSnapshot(string $path, LoggerInterface $logger = new NullLogger()): Snapshot
    {
        $snapshotJson = is_file($path) ? file_get_contents($path) : false;

        if ($snapshotJson === false) {
            throw CodegenException::snapshotUnreadable($path);
        }

        return $this->codec->decodeSnapshot($snapshotJson, $path, $logger);
    }
}
