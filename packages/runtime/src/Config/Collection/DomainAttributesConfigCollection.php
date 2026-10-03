<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config\Collection;

use Stewart\Contracts\Collection\KeyedCollection;
use Stewart\Runtime\Config\DomainAttributesConfig;

/** @extends KeyedCollection<string, DomainAttributesConfig> */
final readonly class DomainAttributesConfigCollection extends KeyedCollection
{
    /** @param iterable<DomainAttributesConfig> $domains */
    public static function keyedByDomain(iterable $domains): self
    {
        return self::keyedBy($domains, static fn(DomainAttributesConfig $domain): string => $domain->domain);
    }

    public function find(string $domain): ?DomainAttributesConfig
    {
        return $this->elementAt($domain);
    }
}
