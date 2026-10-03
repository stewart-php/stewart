<?php

declare(strict_types=1);

namespace Stewart\Codegen\Entity;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\SelectorException;
use Stewart\Contracts\Selector\Collection\SelectorCollection;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\State\Collection\EntityStateCollection;

final readonly class EntityFilter
{
    private function __construct(
        public SelectorCollection $include,
        public SelectorCollection $exclude,
    ) {}

    /**
     * @param list<string> $include
     * @param list<string> $exclude
     * @throws SelectorException
     */
    public static function fromPatterns(array $include = [], array $exclude = []): self
    {
        return new self(SelectorCollection::fromSpecs(...$include), SelectorCollection::fromSpecs(...$exclude));
    }

    public static function allowingEverything(): self
    {
        return new self(SelectorCollection::empty(), SelectorCollection::empty());
    }

    public function allows(EntityId $entityId): bool
    {
        if ($this->exclude->anyMatches($entityId->value)) {
            return false;
        }

        return $this->include->isEmpty() || $this->include->anyMatches($entityId->value);
    }

    public function listUnmatchedIncludes(EntityStateCollection $states): SelectorCollection
    {
        return $this->include->filter(static fn(Selector $selector): bool => $states->filterBySelector($selector)->isEmpty());
    }
}
