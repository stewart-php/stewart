<?php

declare(strict_types=1);

namespace Stewart\Contracts\Registry\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Contracts\Registry\EntityAlias;

/** @extends ListCollection<EntityAlias> */
final readonly class EntityAliasCollection extends ListCollection
{
    /** @param iterable<EntityAlias> $aliases */
    public static function fromAliases(iterable $aliases): self
    {
        return self::fromList($aliases);
    }

    public function contains(EntityAlias $alias): bool
    {
        return $this->containsWhere($alias->equals(...));
    }

    public function withAddedMembers(self $aliases): self
    {
        $merged = $this;

        foreach ($aliases as $alias) {
            $merged = $merged->contains($alias) ? $merged : $merged->withAppendedElement($alias);
        }

        return $merged;
    }

    public function withoutMembers(self $aliases): self
    {
        return $this->filter(static fn(EntityAlias $alias): bool => !$aliases->contains($alias));
    }
}
