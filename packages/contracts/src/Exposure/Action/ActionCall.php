<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure\Action;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exposure\Command\ExposedCommand;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\State\EventContext;

final readonly class ActionCall implements ExposedCommand
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public array $data,
        public EventContext $context,
    ) {}

    public function getContext(): EventContext
    {
        return $this->context;
    }

    public function getRequestedState(): ?ExposedState
    {
        return null;
    }

    public function findString(string $field): ?string
    {
        $value = $this->data[$field] ?? null;

        return \is_string($value) ? $value : null;
    }

    public function findNumber(string $field): int|float|null
    {
        $value = $this->data[$field] ?? null;

        return \is_int($value) || \is_float($value) ? $value : null;
    }

    public function findBool(string $field): ?bool
    {
        $value = $this->data[$field] ?? null;

        return \is_bool($value) ? $value : null;
    }

    public function findEntityId(string $field): ?EntityId
    {
        $value = $this->findString($field);

        return $value === null ? null : EntityId::tryFromString($value);
    }
}
