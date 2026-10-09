<?php

declare(strict_types=1);

namespace Stewart\Client\Connection\Command\Component;

use Stewart\Client\Component\ExposedEntityAddress;
use Stewart\Client\Component\ExposedEntityReconcile;
use Stewart\Client\Connection\Command\HaCommand;

final readonly class ReconcileExposedEntities implements HaCommand
{
    public function __construct(public ExposedEntityReconcile $reconcile) {}

    public function type(): string
    {
        return 'stewart/entity/reconcile';
    }

    public function describe(): string
    {
        return \sprintf('%s %s keeping %d entities', $this->type(), $this->reconcile->instance, $this->reconcile->keptAddresses->count());
    }

    public function toMessage(): array
    {
        return [
            'type' => $this->type(),
            'instance' => $this->reconcile->instance->value,
            'keep' => $this->reconcile->keptAddresses->mapToList(
                static fn(ExposedEntityAddress $address): array => ['app' => $address->appId->value, 'key' => $address->key->value],
            ),
            'keep_apps' => $this->reconcile->keptApps->toStrings(),
        ];
    }
}
