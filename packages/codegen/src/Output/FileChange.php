<?php

declare(strict_types=1);

namespace Stewart\Codegen\Output;

final readonly class FileChange
{
    public function __construct(
        public string $path,
        public FileOutcome $outcome,
    ) {}
}
