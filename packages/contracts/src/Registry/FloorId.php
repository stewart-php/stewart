<?php

declare(strict_types=1);

namespace Stewart\Contracts\Registry;

final readonly class FloorId extends RegistryId
{
    protected function describeKind(): string
    {
        return 'Floor';
    }
}
