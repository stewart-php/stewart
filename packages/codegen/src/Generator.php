<?php

declare(strict_types=1);

namespace Stewart\Codegen;

use Stewart\Codegen\Emitter\SourceTree;
use Stewart\Codegen\Exception\CodegenException;
use Stewart\Codegen\Model\GenerationModelFactory;
use Stewart\Codegen\Output\CheckResult;
use Stewart\Codegen\Output\GenerationReport;
use Stewart\Codegen\Output\OutputDirectory;
use Stewart\Codegen\Output\ShrinkPolicy;
use Stewart\Codegen\Snapshot\Snapshot;

final readonly class Generator
{
    public function __construct(
        private GenerationModelFactory $models,
        private SourceTree $sources,
    ) {}

    /** @throws CodegenException */
    public function writeFiles(Snapshot $snapshot, GenerationTarget $target, GenerationOptions $options, ShrinkPolicy $shrinkPolicy): GenerationReport
    {
        $model = $this->models->buildModelFromSnapshot($snapshot, $options);

        return new GenerationReport(
            changes: new OutputDirectory($target->directory)->writeChanges($this->sources->renderFiles($model, $target), $shrinkPolicy),
            entities: $model->countEntities(),
            domains: \count($model->listEntityDomains()),
            services: $model->countServices(),
            ignored: \count($model->ignoredEntityIds),
            attributes: $model->countAttributes(),
            warnings: $model->warnings,
            haVersion: $snapshot->haVersion,
        );
    }

    /** @throws CodegenException */
    public function checkAgainstSnapshot(Snapshot $snapshot, GenerationTarget $target, GenerationOptions $options): CheckResult
    {
        $model = $this->models->buildModelFromSnapshot($snapshot, $options);

        return new CheckResult(
            new OutputDirectory($target->directory)->planChanges($this->sources->renderFiles($model, $target)),
            $model->warnings,
        );
    }
}
