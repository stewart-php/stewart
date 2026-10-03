<?php

declare(strict_types=1);

namespace Stewart\Codegen\Attribute;

use Stewart\Contracts\Exception\SelectorException;
use Stewart\Contracts\Selector\Collection\SelectorCollection;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\Selector\SelectorKind;

final readonly class KeyPatterns
{
    private SelectorCollection $selectors;

    /**
     * @param list<string> $patterns
     * @throws SelectorException
     */
    public function __construct(array $patterns = [])
    {
        $this->selectors = SelectorCollection::fromSpecs(...$patterns);
    }

    public function matches(string $key): bool
    {
        return $this->selectors->anyMatches($key);
    }

    /** @return list<string> */
    public function exactEntries(): array
    {
        return $this->selectors
            ->filter(static fn(Selector $selector): bool => $selector->getKind() === SelectorKind::Exact)
            ->mapToList(static fn(Selector $selector): string => $selector->getPattern());
    }
}
