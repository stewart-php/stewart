<?php

declare(strict_types=1);

namespace Stewart\Codegen\Model\Collection;

use Stewart\Codegen\Model\DomainModel;
use Stewart\Contracts\Collection\ListCollection;

/** @extends ListCollection<DomainModel> */
final readonly class DomainModelCollection extends ListCollection
{
    /** @param iterable<DomainModel> $domains */
    public static function fromDomains(iterable $domains): self
    {
        return self::fromList($domains);
    }

    public function havingEntities(): self
    {
        return $this->filter(static fn(DomainModel $domain): bool => $domain->hasEntities());
    }

    public function havingServices(): self
    {
        return $this->filter(static fn(DomainModel $domain): bool => $domain->hasServices());
    }

    public function countAttributes(): int
    {
        return array_sum($this->mapToList(static fn(DomainModel $domain): int => \count($domain->attributes)));
    }
}
