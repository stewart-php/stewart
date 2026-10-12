<?php

declare(strict_types=1);

namespace Stewart\Codegen\Output;

use Stewart\Codegen\Model\GenerationWarning;
use Stewart\Codegen\Output\Collection\FileChangeCollection;

final readonly class CheckResult
{
    /** @param list<GenerationWarning> $warnings */
    public function __construct(
        public FileChangeCollection $changes,
        public array $warnings,
    ) {}

    public function listStaleFiles(): FileChangeCollection
    {
        return $this->changes->filterStale();
    }

    public function isClean(): bool
    {
        return $this->changes->isClean();
    }
}
