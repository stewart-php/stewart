<?php

declare(strict_types=1);

namespace Stewart\Contracts\Selector\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\SelectorException;
use Stewart\Contracts\Selector\Selector;

/** @extends ListCollection<Selector> */
final readonly class SelectorCollection extends ListCollection
{
    /** @throws SelectorException */
    public static function fromSpecs(string|EntityId|Selector ...$specs): self
    {
        return self::fromList(array_map(Selector::fromSpec(...), $specs));
    }

    public function anyMatches(string $candidate): bool
    {
        return $this->containsWhere(static fn(Selector $selector): bool => $selector->matches($candidate));
    }
}
