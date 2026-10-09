<?php

declare(strict_types=1);

namespace Stewart\Contracts\Registry\Update;

use Stewart\Contracts\Registry\AreaId;

// A null area makes the entity follow its device's area.
final readonly class EntityAreaChange
{
    public function __construct(public ?AreaId $areaId) {}
}
