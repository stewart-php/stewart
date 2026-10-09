<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

use Stewart\Contracts\Entity\EntityId;

final readonly class ExposedEntitySnapshot
{
    /** @param array<string, mixed> $attributes */
    public function __construct(
        public EntityId $entityId,
        public ExposedState $state,
        public array $attributes,
        public bool $available,
    ) {}
}
