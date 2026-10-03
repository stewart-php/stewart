<?php

declare(strict_types=1);

namespace Stewart\Runtime\App\Collection;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Collection\KeyedCollection;
use Stewart\Runtime\App\AppDefinition;

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
}
