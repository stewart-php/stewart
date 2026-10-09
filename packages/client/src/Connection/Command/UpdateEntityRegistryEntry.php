<?php

declare(strict_types=1);

namespace Stewart\Client\Connection\Command;

use LogicException;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Registry\EntityAlias;
use Stewart\Contracts\Registry\LabelId;
use Stewart\Contracts\Registry\Update\EntityRegistryUpdate;

final readonly class UpdateEntityRegistryEntry implements HaCommand
{
    public function __construct(
        public EntityId $entityId,
        public EntityRegistryUpdate $update,
    ) {
        if ($update->needsCurrentEntry()) {
            throw new LogicException('Resolve added and removed labels and aliases against the current entry before sending.');
        }
    }

    public function type(): string
    {
        return 'config/entity_registry/update';
    }

    public function describe(): string
    {
        return \sprintf('%s %s', $this->type(), $this->entityId->value);
    }

    public function toMessage(): array
    {
        $update = $this->update;
        $message = ['type' => $this->type(), 'entity_id' => $this->entityId->value];

        if ($update->name !== null) {
            $message['name'] = $update->name->name;
        }

        if ($update->icon !== null) {
            $message['icon'] = $update->icon->icon;
        }

        if ($update->area !== null) {
            $message['area_id'] = $update->area->areaId?->value;
        }

        if ($update->labels?->replacement !== null) {
            $message['labels'] = array_map(static fn(LabelId $labelId): string => $labelId->value, $update->labels->replacement);
        }

        if ($update->aliases?->replacement !== null) {
            $message['aliases'] = array_map(static fn(EntityAlias $alias): ?string => $alias->phrase, $update->aliases->replacement);
        }

        if ($update->hidden !== null) {
            $message['hidden_by'] = $update->hidden ? EntityRegistryUpdate::CHANGED_BY_USER : null;
        }

        if ($update->disabled !== null) {
            $message['disabled_by'] = $update->disabled ? EntityRegistryUpdate::CHANGED_BY_USER : null;
        }

        return $message;
    }
}
