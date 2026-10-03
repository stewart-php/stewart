<?php

declare(strict_types=1);

namespace Stewart\Codegen\Output;

use Stewart\Codegen\Model\Collection\GenerationWarningCollection;
use Stewart\Codegen\Output\Collection\FileChangeCollection;

final readonly class CheckResult
{
    public function __construct(
        public FileChangeCollection $changes,
        public GenerationWarningCollection $warnings,
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
