<?php

declare(strict_types=1);

namespace Stewart\Codegen\Emitter;

use Stewart\Codegen\Model\Collection\DomainModelCollection;
use Stewart\Codegen\Model\DomainModel;
use Stewart\Codegen\Model\GenerationModel;

final readonly class EntitiesRootEmitter extends RootEmitter
{
    protected function getClassName(): string
    {
        return 'Entities';
    }

    protected function getClassComment(): string
    {
        return 'Every entity Home Assistant knows, by domain.';
    }

    protected function listDomains(GenerationModel $model): DomainModelCollection
    {
        return $model->listEntityDomains();
    }

    protected function getDomainClassName(DomainModel $domain): string
    {
        return $domain->getEntitiesClass();
    }

    protected function buildPropertyComment(string $domain): string
    {
        return \sprintf('Entities in the `%s` domain.', $domain);
    }
}
