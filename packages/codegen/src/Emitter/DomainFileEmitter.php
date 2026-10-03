<?php

declare(strict_types=1);

namespace Stewart\Codegen\Emitter;

use Stewart\Codegen\Model\DomainModel;
use Stewart\Codegen\Output\GeneratedFile;

interface DomainFileEmitter
{
    public function supportsDomain(DomainModel $domain): bool;

    public function emitFile(DomainModel $domain, EmitContext $context): GeneratedFile;
}
