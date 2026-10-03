<?php

declare(strict_types=1);

namespace Stewart\Codegen\Service\Collection;

use Stewart\Codegen\Service\ServiceDefinition;
use Stewart\Contracts\Collection\ListCollection;

/** @extends ListCollection<ServiceDefinition> */
final readonly class ServiceDefinitionCollection extends ListCollection
{
    /** @param iterable<ServiceDefinition> $definitions */
    public static function fromDefinitions(iterable $definitions): self
    {
        return self::fromList($definitions);
    }

    public function forEntityHandle(string $domain): self
    {
        return $this->filter(static fn(ServiceDefinition $service): bool => $service->belongsOnHandle($domain));
    }
}
