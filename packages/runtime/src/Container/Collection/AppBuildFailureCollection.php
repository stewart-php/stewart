<?php

declare(strict_types=1);

namespace Stewart\Runtime\Container\Collection;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Collection\KeyedCollection;
use Stewart\Runtime\Container\AppBuildFailure;

/** @extends KeyedCollection<string, AppBuildFailure> */
final readonly class AppBuildFailureCollection extends KeyedCollection
{
    /** @param iterable<AppBuildFailure> $failures */
    public static function keyedByAppId(iterable $failures): self
    {
        return self::keyedBy($failures, static fn(AppBuildFailure $failure): string => $failure->appId->value);
    }

    public function find(AppId $id): ?AppBuildFailure
    {
        return $this->elementAt($id->value);
    }
}
