<?php

declare(strict_types=1);

namespace Stewart\Codegen\Emitter;

use Stewart\Codegen\GenerationTarget;
use Stewart\Codegen\Model\GenerationModel;
use Stewart\Codegen\Output\GeneratedFile;

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

    /** @return list<GeneratedFile> */
    public function renderFiles(GenerationModel $model, GenerationTarget $target): array
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

        usort($files, static fn(GeneratedFile $a, GeneratedFile $b): int => strcmp($a->path, $b->path));

        return $files;
    }
}
