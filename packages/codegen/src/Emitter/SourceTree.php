<?php

declare(strict_types=1);

namespace Stewart\Codegen\Emitter;

use Stewart\Codegen\GenerationTarget;
use Stewart\Codegen\Model\GenerationModel;
use Stewart\Codegen\Output\Collection\GeneratedFileCollection;

final readonly class SourceTree
{
    /**
     * @param iterable<ModelFileEmitter> $modelEmitters
     * @param iterable<DomainFileEmitter> $domainEmitters
     */
    public function __construct(
        private iterable $modelEmitters,
        private iterable $domainEmitters,
        private GeneratedCodePrinter $printer,
    ) {}

    public function renderFiles(GenerationModel $model, GenerationTarget $target): GeneratedFileCollection
    {
        $context = new EmitContext($target, $this->printer);
        $files = [];

        foreach ($this->modelEmitters as $emitter) {
            $files[] = $emitter->emitFile($model, $context);
        }

        foreach ($model->domains as $domain) {
            foreach ($this->domainEmitters as $emitter) {
                if ($emitter->supportsDomain($domain)) {
                    $files[] = $emitter->emitFile($domain, $context);
                }
            }
        }

        return GeneratedFileCollection::fromFilesSortedByPath($files);
    }
}
