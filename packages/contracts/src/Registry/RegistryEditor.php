<?php

declare(strict_types=1);

namespace Stewart\Contracts\Registry;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\IdentifierException;
use Stewart\Contracts\Exception\RegistryEditException;
use Stewart\Contracts\Registry\Update\EntityRegistryUpdate;

interface RegistryEditor
{
    /** @throws RegistryEditException|IdentifierException */
    public function updateEntity(EntityId|string $entityId, EntityRegistryUpdate $update): RegisteredEntity;
}
