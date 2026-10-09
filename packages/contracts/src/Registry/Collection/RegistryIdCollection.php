<?php

declare(strict_types=1);

namespace Stewart\Contracts\Registry\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Contracts\Registry\RegistryId;

/**
 * @template T of RegistryId
 * @extends ListCollection<T>
 */
abstract readonly class RegistryIdCollection extends ListCollection
{
    /** @param iterable<T> $ids */
    public static function fromIds(iterable $ids): static
    {
        return static::fromList($ids);
    }

    /** @param T $id */
    public function contains(RegistryId $id): bool
    {
        return $this->containsWhere(static fn(RegistryId $member): bool => $member->equals($id));
    }

    /** @param static $other */
    public function containsAnyOf(self $other): bool
    {
        return $other->containsWhere($this->contains(...));
    }

    /** @param static $ids */
    public function withAddedMembers(self $ids): static
    {
        $merged = $this;

        foreach ($ids as $id) {
            $merged = $merged->contains($id) ? $merged : $merged->withAppendedElement($id);
        }

        return $merged;
    }

    /** @param static $ids */
    public function withoutMembers(self $ids): static
    {
        return $this->filter(static fn(RegistryId $id): bool => !$ids->contains($id));
    }

    /** @return list<string> */
    public function toStrings(): array
    {
        return $this->mapToList(static fn(RegistryId $id): string => $id->value);
    }
}
