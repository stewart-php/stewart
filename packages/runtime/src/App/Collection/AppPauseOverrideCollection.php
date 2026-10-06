<?php

declare(strict_types=1);

namespace Stewart\Runtime\App\Collection;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Collection\KeyedCollection;
use Stewart\Runtime\App\AppPauseOverride;

/** @extends KeyedCollection<string, AppPauseOverride> */
final readonly class AppPauseOverrideCollection extends KeyedCollection
{
    /** @param iterable<AppPauseOverride> $overrides */
    public static function keyedByAppId(iterable $overrides): self
    {
        return self::keyedBy($overrides, static fn(AppPauseOverride $override): string => $override->appId->value);
    }

    public function find(AppId $id): ?AppPauseOverride
    {
        return $this->elementAt($id->value);
    }
}
