<?php

declare(strict_types=1);

namespace Stewart\Runtime\App\Collection;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Collection\KeyedCollection;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\Model\AppVerbosity;

/** @extends KeyedCollection<string, AppDefinition> */
final readonly class AppDefinitionCollection extends KeyedCollection
{
    /** @param iterable<AppDefinition> $definitions */
    public static function keyedByAppId(iterable $definitions): self
    {
        return self::keyedBy($definitions, static fn(AppDefinition $definition): string => $definition->id->value);
    }

    public function find(AppId $id): ?AppDefinition
    {
        return $this->elementAt($id->value);
    }

    public function listAppIds(): AppIdCollection
    {
        return AppIdCollection::fromList($this->mapToList(static fn(AppDefinition $definition): AppId => $definition->id));
    }

    /** @return array<string, AppVerbosity> */
    public function listVerbositiesByAppId(): array
    {
        return array_map(static fn(AppDefinition $definition): AppVerbosity => $definition->verbosity, $this->elements());
    }
}
