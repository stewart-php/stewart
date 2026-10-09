<?php

declare(strict_types=1);

namespace Stewart\Contracts\Registry\Update;

use Stewart\Contracts\Registry\Collection\LabelIdCollection;
use Stewart\Contracts\Registry\LabelId;
use Stewart\Contracts\Wire\ListOf;

final readonly class EntityLabelsChange
{
    /**
     * @param list<LabelId>|null $replacement
     * @param list<LabelId> $added
     * @param list<LabelId> $removed
     */
    public function __construct(
        #[ListOf(LabelId::class)]
        public ?array $replacement = null,
        #[ListOf(LabelId::class)]
        public array $added = [],
        #[ListOf(LabelId::class)]
        public array $removed = [],
    ) {}

    public static function replacingWith(LabelIdCollection $labelIds): self
    {
        return new self($labelIds->listValues());
    }

    public function withAdded(LabelIdCollection $labelIds): self
    {
        if ($this->replacement !== null) {
            return self::replacingWith(LabelIdCollection::fromIds($this->replacement)->withAddedMembers($labelIds));
        }

        return new self(
            added: LabelIdCollection::fromIds($this->added)->withAddedMembers($labelIds)->listValues(),
            removed: LabelIdCollection::fromIds($this->removed)->withoutMembers($labelIds)->listValues(),
        );
    }

    public function withRemoved(LabelIdCollection $labelIds): self
    {
        if ($this->replacement !== null) {
            return self::replacingWith(LabelIdCollection::fromIds($this->replacement)->withoutMembers($labelIds));
        }

        return new self(
            added: LabelIdCollection::fromIds($this->added)->withoutMembers($labelIds)->listValues(),
            removed: LabelIdCollection::fromIds($this->removed)->withAddedMembers($labelIds)->listValues(),
        );
    }

    public function needsCurrentLabels(): bool
    {
        return $this->replacement === null;
    }

    public function resolveAgainst(LabelIdCollection $current): self
    {
        return self::replacingWith($this->applyTo($current));
    }

    public function applyTo(LabelIdCollection $current): LabelIdCollection
    {
        $base = $this->replacement === null ? $current : LabelIdCollection::fromIds($this->replacement);

        return $base->withAddedMembers(LabelIdCollection::fromIds($this->added))->withoutMembers(LabelIdCollection::fromIds($this->removed));
    }
}
