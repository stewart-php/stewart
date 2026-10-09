<?php

declare(strict_types=1);

namespace Stewart\Client\Component;

use Stewart\Client\Exception\HaClientException;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\IdentifierException;
use Stewart\Support\Json\JsonShape;

final readonly class ExposedEntitySnapshot
{
    /** @param array<string, mixed> $attributes */
    public function __construct(
        public EntityId $entityId,
        public int|float|string|bool|null $state,
        public array $attributes,
        public bool $available,
    ) {}

    /**
     * @param array<array-key, mixed> $result
     * @throws HaClientException
     */
    public static function fromUpsertResult(array $result): self
    {
        $entityId = $result['entity_id'] ?? null;
        $state = $result['state'] ?? null;
        $attributes = $result['attributes'] ?? null;
        $available = $result['available'] ?? null;

        if (!\is_string($entityId) || !\is_array($attributes) || !\is_bool($available) || !(\is_scalar($state) || $state === null)) {
            throw HaClientException::protocolViolation('a stewart/entity/upsert result without entity_id, state, attributes and available');
        }

        try {
            return new self(new EntityId($entityId), $state, JsonShape::treatKeysAsStrings($attributes), $available);
        } catch (IdentifierException $e) {
            throw HaClientException::protocolViolation('a stewart/entity/upsert result with an invalid entity_id', $e);
        }
    }
}
