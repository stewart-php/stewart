<?php

declare(strict_types=1);

namespace Stewart\Testing\Registry;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Registry\Update\EntityRegistryUpdate;

final readonly class RecordedRegistryUpdate
{
    public function __construct(
        public EntityId $entityId,
        public EntityRegistryUpdate $update,
    ) {}
}
