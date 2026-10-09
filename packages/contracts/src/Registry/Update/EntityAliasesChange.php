<?php

declare(strict_types=1);

namespace Stewart\Contracts\Registry\Update;

use Stewart\Contracts\Registry\Collection\EntityAliasCollection;
use Stewart\Contracts\Registry\EntityAlias;
use Stewart\Contracts\Wire\ListOf;

final readonly class EntityAliasesChange
{
    /**
     * @param list<EntityAlias>|null $replacement
     * @param list<EntityAlias> $added
     * @param list<EntityAlias> $removed
     */
    public function __construct(
        #[ListOf(EntityAlias::class)]
        public ?array $replacement = null,
        #[ListOf(EntityAlias::class)]
        public array $added = [],
        #[ListOf(EntityAlias::class)]
        public array $removed = [],
    ) {}

    public static function replacingWith(EntityAliasCollection $aliases): self
    {
        return new self($aliases->listValues());
    }

    public function withAdded(EntityAliasCollection $aliases): self
    {
        if ($this->replacement !== null) {
            return self::replacingWith(EntityAliasCollection::fromAliases($this->replacement)->withAddedMembers($aliases));
        }

        return new self(
            added: EntityAliasCollection::fromAliases($this->added)->withAddedMembers($aliases)->listValues(),
            removed: EntityAliasCollection::fromAliases($this->removed)->withoutMembers($aliases)->listValues(),
        );
    }

    public function withRemoved(EntityAliasCollection $aliases): self
    {
        if ($this->replacement !== null) {
            return self::replacingWith(EntityAliasCollection::fromAliases($this->replacement)->withoutMembers($aliases));
        }

        return new self(
            added: EntityAliasCollection::fromAliases($this->added)->withoutMembers($aliases)->listValues(),
            removed: EntityAliasCollection::fromAliases($this->removed)->withAddedMembers($aliases)->listValues(),
        );
    }

    public function needsCurrentAliases(): bool
    {
        return $this->replacement === null;
    }

    public function resolveAgainst(EntityAliasCollection $current): self
    {
        return self::replacingWith($this->applyTo($current));
    }

    public function applyTo(EntityAliasCollection $current): EntityAliasCollection
    {
        $base = $this->replacement === null ? $current : EntityAliasCollection::fromAliases($this->replacement);

        return $base->withAddedMembers(EntityAliasCollection::fromAliases($this->added))->withoutMembers(EntityAliasCollection::fromAliases($this->removed));
    }
}
