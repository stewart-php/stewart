<?php

declare(strict_types=1);

namespace Stewart\Codegen\Emitter;

use Stewart\Codegen\Model\Collection\DomainModelCollection;
use Stewart\Codegen\Model\DomainModel;
use Stewart\Codegen\Model\GenerationModel;

final readonly class ActionsRootEmitter extends RootEmitter
{
    protected function getClassName(): string
    {
        return 'Actions';
    }

    protected function getClassComment(): string
    {
        return 'Every action Home Assistant offers, by domain.';
    }

    protected function listDomains(GenerationModel $model): DomainModelCollection
    {
        return $model->listActionDomains();
    }

    protected function getDomainClassName(DomainModel $domain): string
    {
        return $domain->getActionsClass();
    }

    protected function buildPropertyComment(string $domain): string
    {
        return \sprintf('Actions in the `%s` domain.', $domain);
    }
}
