<?php

declare(strict_types=1);

namespace Stewart\Codegen\Output;

use Stewart\Codegen\Model\GenerationWarning;
use Stewart\Codegen\Output\Collection\FileChangeCollection;

final readonly class GenerationReport
{
    /** @param list<GenerationWarning> $warnings */
    public function __construct(
        public FileChangeCollection $changes,
        public int $entities,
        public int $domains,
        public int $services,
        public int $ignored,
        public int $attributes,
        public array $warnings,
        public string $haVersion,
    ) {}

    public function summary(): string
    {
        return \sprintf(
            'entities: %d in %d domains · attributes: %d typed · services: %d · files created/updated/unchanged/deleted: %d/%d/%d/%d · ignored: %d · from Home Assistant %s',
            $this->entities,
            $this->domains,
            $this->attributes,
            $this->services,
            $this->changes->countWithOutcome(FileOutcome::Created),
            $this->changes->countWithOutcome(FileOutcome::Updated),
            $this->changes->countWithOutcome(FileOutcome::Unchanged),
            $this->changes->countWithOutcome(FileOutcome::Deleted),
            $this->ignored,
            $this->haVersion,
        );
    }
}
