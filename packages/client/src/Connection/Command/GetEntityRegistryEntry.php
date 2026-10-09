<?php

declare(strict_types=1);

namespace Stewart\Client\Connection\Command;

use Stewart\Contracts\Entity\EntityId;

final readonly class GetEntityRegistryEntry implements HaCommand
{
    public function __construct(public EntityId $entityId) {}

    public function type(): string
    {
        return 'config/entity_registry/get';
    }

    public function describe(): string
    {
        return \sprintf('%s %s', $this->type(), $this->entityId->value);
    }

    public function toMessage(): array
    {
        return ['type' => $this->type(), 'entity_id' => $this->entityId->value];
    }
}
