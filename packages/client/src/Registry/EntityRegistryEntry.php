<?php

declare(strict_types=1);

namespace Stewart\Client\Registry;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\IdentifierException;

final readonly class EntityRegistryEntry
{
    public function __construct(
        public EntityId $entityId,
        public ?string $disabledBy = null,
        public ?string $hiddenBy = null,
        public ?string $name = null,
    ) {}

    public function isDisabled(): bool
    {
        return $this->disabledBy !== null;
    }

    public function isHidden(): bool
    {
        return $this->hiddenBy !== null;
    }

    /**
     * @param array<array-key, mixed> $raw
     * @throws IdentifierException
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            entityId: new EntityId(self::readString($raw, 'entity_id') ?? ''),
            disabledBy: self::readString($raw, 'disabled_by'),
            hiddenBy: self::readString($raw, 'hidden_by'),
            name: self::readString($raw, 'name'),
        );
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'entity_id' => $this->entityId->value,
            'disabled_by' => $this->disabledBy,
            'hidden_by' => $this->hiddenBy,
            'name' => $this->name,
        ];
    }

    /** @param array<array-key, mixed> $raw */
    private static function readString(array $raw, string $key): ?string
    {
        $value = $raw[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
