<?php

declare(strict_types=1);

namespace Stewart\Codegen\Action;

use Stewart\Codegen\Action\Collection\ActionDefinitionCollection;

final readonly class ActionCatalog
{
    /** @param array<string, ActionDefinitionCollection> $byDomain */
    public function __construct(private array $byDomain) {}

    /** @return list<string> */
    public function listDomains(): array
    {
        return array_keys($this->byDomain);
    }

    public function forDomain(string $domain): ActionDefinitionCollection
    {
        return $this->byDomain[$domain] ?? ActionDefinitionCollection::empty();
    }

    public function forEntityHandle(string $domain): ActionDefinitionCollection
    {
        return $this->forDomain($domain)->forEntityHandle($domain);
    }
}
