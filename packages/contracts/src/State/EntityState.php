<?php

declare(strict_types=1);

namespace Stewart\Contracts\State;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;

final readonly class EntityState
{
    public const string UNAVAILABLE = 'unavailable';

    public const string UNKNOWN = 'unknown';

    /** @param array<string, mixed> $attributes */
    public function __construct(
        public EntityId $entityId,
        public string $state,
        public array $attributes = [],
        public ?Instant $lastChangedAt = null,
        public ?Instant $lastUpdatedAt = null,
        public ?EventContext $context = null,
    ) {}

    public function getDomain(): string
    {
        return $this->entityId->domain;
    }

    public function getObjectId(): string
    {
        return $this->entityId->objectId;
    }

    public function isUnavailable(): bool
    {
        return $this->state === self::UNAVAILABLE || $this->state === self::UNKNOWN;
    }

    public function wasLastChangedBy(EventContext $context): bool
    {
        return $this->context !== null && $context->isSameOrParentOf($this->context);
    }

    public function getAttribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function getFloatAttribute(string $name): ?float
    {
        $value = $this->attributes[$name] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    public function getIntAttribute(string $name): ?int
    {
        $value = $this->attributes[$name] ?? null;

        if (!\is_int($value) && !\is_float($value) && !\is_string($value)) {
            return null;
        }

        return filter_var($value, \FILTER_VALIDATE_INT, \FILTER_NULL_ON_FAILURE);
    }

    public function getStringAttribute(string $name): ?string
    {
        $value = $this->attributes[$name] ?? null;

        return \is_string($value) ? $value : null;
    }

    public function getBoolAttribute(string $name): ?bool
    {
        $value = $this->attributes[$name] ?? null;

        return \is_bool($value) ? $value : null;
    }

    /** @return array<array-key, mixed>|null */
    public function getArrayAttribute(string $name): ?array
    {
        $value = $this->attributes[$name] ?? null;

        return \is_array($value) ? $value : null;
    }

    public function getStateAsFloat(): ?float
    {
        return is_numeric($this->state) ? (float) $this->state : null;
    }

    public function getHeldDuration(Clock $clock): ?Duration
    {
        return $this->lastChangedAt === null ? null : $clock->getNow()->elapsedSince($this->lastChangedAt);
    }

    public function hasHeldFor(Duration $duration, Clock $clock): bool
    {
        $held = $this->getHeldDuration($clock);

        return $held !== null && !$duration->isLongerThan($held);
    }

    public function getFriendlyName(): string
    {
        $name = $this->attributes['friendly_name'] ?? null;

        return \is_string($name) ? $name : $this->entityId->value;
    }
}
