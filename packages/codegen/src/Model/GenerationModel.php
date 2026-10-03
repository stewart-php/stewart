<?php

declare(strict_types=1);

namespace Stewart\Codegen\Model;

use Stewart\Codegen\Model\Collection\DomainModelCollection;
use Stewart\Codegen\Model\Collection\GenerationWarningCollection;
use Stewart\Contracts\Entity\Collection\EntityIdCollection;

final readonly class GenerationModel
{
    public function __construct(
        public DomainModelCollection $domains,
        public EntityIdCollection $entityIds,
        public EntityIdCollection $ignoredEntityIds,
        public GenerationWarningCollection $warnings,
    ) {}

    public function listEntityDomains(): DomainModelCollection
    {
        return $this->domains->havingEntities();
    }

    public function listServiceDomains(): DomainModelCollection
    {
        return $this->domains->havingServices();
    }

    public function countEntities(): int
    {
        return \count($this->entityIds);
    }

    public function countAttributes(): int
    {
        return $this->listEntityDomains()->countAttributes();
    }

    public function countServices(): int
    {
        return array_sum($this->domains->mapToList(static fn(DomainModel $domain): int => \count($domain->services)));
    }
}
