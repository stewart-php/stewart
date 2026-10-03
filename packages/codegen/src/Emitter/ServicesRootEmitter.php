<?php

declare(strict_types=1);

namespace Stewart\Codegen\Emitter;

use Stewart\Codegen\Model\Collection\DomainModelCollection;
use Stewart\Codegen\Model\DomainModel;
use Stewart\Codegen\Model\GenerationModel;

final readonly class ServicesRootEmitter extends RootEmitter
{
    protected function getClassName(): string
    {
        return 'Services';
    }

    protected function getClassComment(): string
    {
        return 'Every service Home Assistant offers, by domain.';
    }

    protected function listDomains(GenerationModel $model): DomainModelCollection
    {
        return $model->listServiceDomains();
    }

    protected function getDomainClassName(DomainModel $domain): string
    {
        return $domain->getServicesClass();
    }

    protected function buildPropertyComment(string $domain): string
    {
        return \sprintf('Services in the `%s` domain.', $domain);
    }
}
