<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config\Collection;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Collection\KeyedCollection;
use Stewart\Runtime\Config\AppOverride;

/** @extends KeyedCollection<string, AppOverride> */
final readonly class AppOverrideCollection extends KeyedCollection
{
    /** @param iterable<AppOverride> $overrides */
    public static function keyedByAppId(iterable $overrides): self
    {
        return self::keyedBy($overrides, static fn(AppOverride $override): string => $override->id->value);
    }

    public function find(AppId $id): ?AppOverride
    {
        return $this->elementAt($id->value);
    }

    // Environment variable names spell "my-app" as MY_APP, so an override may name its app either way.
    public function findMatchingApp(AppId $id): ?AppOverride
    {
        return $this->find($id) ?? $this->findFirstWhere(static fn(AppOverride $override): bool => $override->id->collidesWith($id));
    }
}
