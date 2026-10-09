<?php

declare(strict_types=1);

namespace Stewart\Client\Connection\Command\Component;

use Stewart\Client\Component\ExposedEntityAddress;
use Stewart\Client\Component\ExposedEntityDefinition;
use Stewart\Client\Component\ExposedStateFields;
use Stewart\Client\Connection\Command\HaCommand;
use Stewart\Contracts\Exposure\ExposedStateChange;

final readonly class UpsertExposedEntity implements HaCommand
{
    public function __construct(
        public ExposedEntityAddress $address,
        public ExposedEntityDefinition $definition,
        public ExposedStateChange $change,
    ) {}

    public function type(): string
    {
        return 'stewart/entity/upsert';
    }

    public function describe(): string
    {
        return \sprintf('%s %s', $this->type(), $this->address->describe());
    }

    public function toMessage(): array
    {
        return [
            'type' => $this->type(),
            ...$this->address->toMessageFields(),
            ...$this->definition->toMessageFields(),
            ...ExposedStateFields::formatStateChange($this->change),
        ];
    }
}
