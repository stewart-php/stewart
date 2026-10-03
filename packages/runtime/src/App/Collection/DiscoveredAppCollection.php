<?php

declare(strict_types=1);

namespace Stewart\Runtime\App\Collection;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Collection\ListCollection;
use Stewart\Runtime\App\DiscoveredApp;

/** @extends ListCollection<DiscoveredApp> */
final readonly class DiscoveredAppCollection extends ListCollection
{
    /** @param iterable<DiscoveredApp> $apps */
    public static function fromApps(iterable $apps): self
    {
        return self::fromList($apps)->sortedBy(static fn(DiscoveredApp $a, DiscoveredApp $b): int => strcmp($a->id->value, $b->id->value));
    }

    public function listAppIds(): AppIdCollection
    {
        return AppIdCollection::fromIds($this->mapToList(static fn(DiscoveredApp $app): AppId => $app->id));
    }
}
