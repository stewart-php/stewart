<?php

declare(strict_types=1);

namespace Stewart\Codegen\Attribute\Collection;

use Stewart\Codegen\Attribute\AttributeFilter;
use Stewart\Contracts\Collection\KeyedCollection;

/** @extends KeyedCollection<string, AttributeFilter> */
final readonly class AttributeFilterCollection extends KeyedCollection
{
    /** @param iterable<AttributeFilter> $filters */
    public static function keyedByDomain(iterable $filters): self
    {
        return self::keyedBy($filters, static fn(AttributeFilter $filter): string => $filter->domain);
    }

    public function find(string $domain): ?AttributeFilter
    {
        return $this->elementAt($domain);
    }
}
