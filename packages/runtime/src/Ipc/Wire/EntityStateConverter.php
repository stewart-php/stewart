<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Wire;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Json\EpochInstantConverter;
use Stewart\Runtime\Json\StringIdentifierConverter;
use Stewart\Runtime\Json\ValueConverter;
use Stewart\Support\Exception\JsonShapeException;
use Stewart\Support\Json\JsonShape;

final readonly class EntityStateConverter implements ValueConverter
{
    /** @param StringIdentifierConverter<EntityId> $entityIds */
    public function __construct(
        private StringIdentifierConverter $entityIds,
        private EpochInstantConverter $instants,
        private EventContextConverter $contexts,
    ) {}

    public function handledClass(): string
    {
        return EntityState::class;
    }

    public function keySuffix(): string
    {
        return '';
    }

    /** @return array<string, mixed> */
    public function encodeValue(object $value): array
    {
        \assert($value instanceof EntityState);

        return [
            'entity_id' => $this->entityIds->encodeValue($value->entityId),
            'state' => $value->state,
            // Cast so list-shaped attributes encode as a JSON object, not an array.
            'attributes' => array_is_list($value->attributes) ? (object) $value->attributes : $value->attributes,
            'last_changed_at_us' => $value->lastChangedAt === null ? null : $this->instants->encodeValue($value->lastChangedAt),
            'last_updated_at_us' => $value->lastUpdatedAt === null ? null : $this->instants->encodeValue($value->lastUpdatedAt),
            'context' => $value->context === null ? null : $this->contexts->encodeValue($value->context),
        ];
    }

    /** @throws JsonShapeException */
    public function decodeValue(mixed $value, string $path): EntityState
    {
        if (!\is_array($value)) {
            throw JsonShapeException::wrongType($path, 'an object', get_debug_type($value));
        }

        $state = $value['state'] ?? null;
        $attributes = $value['attributes'] ?? null;
        $context = $value['context'] ?? null;

        if (!\is_string($state)) {
            throw JsonShapeException::wrongType($path . '.state', 'a string', get_debug_type($state));
        }

        if (!\is_array($attributes)) {
            throw JsonShapeException::wrongType($path . '.attributes', 'an object', get_debug_type($attributes));
        }

        return new EntityState(
            $this->entityIds->decodeValue($value['entity_id'] ?? null, $path . '.entity_id'),
            $state,
            JsonShape::treatKeysAsStrings($attributes),
            $this->findInstant($value, 'last_changed_at_us', $path),
            $this->findInstant($value, 'last_updated_at_us', $path),
            $context === null ? null : $this->contexts->decodeValue($context, $path . '.context'),
        );
    }

    /**
     * @param array<array-key, mixed> $state
     * @throws JsonShapeException
     */
    private function findInstant(array $state, string $key, string $path): ?Instant
    {
        $value = $state[$key] ?? null;

        return $value === null ? null : $this->instants->decodeValue($value, $path . '.' . $key);
    }
}
