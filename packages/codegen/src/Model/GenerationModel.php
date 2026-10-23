<?php

declare(strict_types=1);

namespace Stewart\Codegen\Model;

use Stewart\Codegen\Model\Collection\DomainModelCollection;
use Stewart\Contracts\Entity\Collection\EntityIdCollection;
use Stewart\Contracts\Registry\Collection\AreaIdCollection;
use Stewart\Contracts\Registry\Collection\FloorIdCollection;
use Stewart\Contracts\Registry\Collection\LabelIdCollection;

final readonly class GenerationModel
{
    /** @param list<GenerationWarning> $warnings */
    public function __construct(
        public DomainModelCollection $domains,
        public EntityIdCollection $entityIds,
        public EntityIdCollection $ignoredEntityIds,
        public array $warnings,
        public AreaIdCollection $areaIds,
        public FloorIdCollection $floorIds,
        public LabelIdCollection $labelIds,
    ) {}

    public function listEntityDomains(): DomainModelCollection
    {
        return $this->domains->havingEntities();
    }

    public function listActionDomains(): DomainModelCollection
    {
        return $this->domains->havingActions();
    }

    /** @return list<string> */
    public function listActionNames(): array
    {
        $names = [];

        foreach ($this->listActionDomains() as $domain) {
            foreach ($domain->actions as $action) {
                $names[] = $action->definition->domain . '.' . $action->definition->name;
            }
        }

        sort($names);

        return $names;
    }

    public function countEntities(): int
    {
        return \count($this->entityIds);
    }

    public function countAttributes(): int
    {
        return $this->listEntityDomains()->countAttributes();
    }

    public function countActions(): int
    {
        return array_sum($this->domains->mapToList(static fn(DomainModel $domain): int => \count($domain->actions)));
    }
}
