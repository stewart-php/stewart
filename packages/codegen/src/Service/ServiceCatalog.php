<?php

declare(strict_types=1);

namespace Stewart\Codegen\Service;

use Stewart\Codegen\Service\Collection\ServiceDefinitionCollection;

final readonly class ServiceCatalog
{
    /** @param array<string, ServiceDefinitionCollection> $byDomain */
    public function __construct(private array $byDomain) {}

    /** @return list<string> */
    public function listDomains(): array
    {
        return array_keys($this->byDomain);
    }

    public function forDomain(string $domain): ServiceDefinitionCollection
    {
        return $this->byDomain[$domain] ?? ServiceDefinitionCollection::empty();
    }

    public function forEntityHandle(string $domain): ServiceDefinitionCollection
    {
        return $this->forDomain($domain)->forEntityHandle($domain);
    }
}
