<?php

declare(strict_types=1);

namespace Stewart\Contracts\State;

final readonly class EventContext
{
    public function __construct(
        public string $id,
        public ?string $parentId = null,
        public ?string $userId = null,
    ) {}

    /** @param array<array-key, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $id = $raw['id'] ?? '';
        $parentId = $raw['parent_id'] ?? null;
        $userId = $raw['user_id'] ?? null;

        return new self(
            id: \is_string($id) ? $id : '',
            parentId: \is_string($parentId) ? $parentId : null,
            userId: \is_string($userId) ? $userId : null,
        );
    }

    public function isSameOrParentOf(self $other): bool
    {
        return $this->id !== '' && ($other->id === $this->id || $other->parentId === $this->id);
    }
}
