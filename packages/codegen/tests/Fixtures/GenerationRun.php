<?php

declare(strict_types=1);

namespace Stewart\Codegen\Tests\Fixtures;

use Stewart\Codegen\Emitter\ManifestEmitter;
use Stewart\Codegen\GenerationOptions;
use Stewart\Codegen\GenerationTarget;
use Stewart\Codegen\Generator;
use Stewart\Codegen\Output\CheckResult;
use Stewart\Codegen\Output\GenerationReport;
use Stewart\Codegen\Output\ShrinkPolicy;
use Stewart\Codegen\Snapshot\Snapshot;

final readonly class GenerationRun
{
    public function __construct(
        private Generator $generator,
        private GenerationTarget $target,
        private GenerationOptions $options,
    ) {}

    public function writeFiles(Snapshot $snapshot): GenerationReport
    {
        return $this->generator->writeFiles($snapshot, $this->target, $this->options, ShrinkPolicy::Allow);
    }

    public function checkAgainstSnapshot(Snapshot $snapshot): CheckResult
    {
        return $this->generator->checkAgainstSnapshot($snapshot, $this->target, $this->options);
    }

    public function getManifestPath(): string
    {
        return $this->target->directory . '/' . ManifestEmitter::CLASS_NAME . '.php';
    }
}
