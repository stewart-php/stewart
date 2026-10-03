<?php

declare(strict_types=1);

namespace Stewart\Codegen\Snapshot;

use Stewart\Codegen\Exception\CodegenException;

final readonly class SnapshotFileWriter
{
    public function __construct(private SnapshotCodec $codec) {}

    /** @throws CodegenException */
    public function writeSnapshot(string $path, Snapshot $snapshot): void
    {
        if (file_put_contents($path, $this->codec->encodeSnapshot($snapshot)) === false) {
            throw CodegenException::fileNotWritable($path);
        }
    }
}
