<?php

declare(strict_types=1);

namespace Stewart\Runtime\App\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Runtime\App\UnmarkedApp;

/** @extends ListCollection<UnmarkedApp> */
final readonly class UnmarkedAppCollection extends ListCollection
{
    /** @param iterable<UnmarkedApp> $apps */
    public static function fromApps(iterable $apps): self
    {
        return self::fromList($apps);
    }

    /** @return list<class-string> */
    public function listClasses(): array
    {
        return $this->mapToList(static fn(UnmarkedApp $app): string => $app->class);
    }
}
