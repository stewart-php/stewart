<?php

declare(strict_types=1);

namespace Stewart\Codegen\Emitter;

use Stewart\Codegen\Model\GenerationModel;
use Stewart\Codegen\Output\GeneratedFile;

interface ModelFileEmitter
{
    public function emitFile(GenerationModel $model, EmitContext $context): GeneratedFile;
}
