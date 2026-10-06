<?php

declare(strict_types=1);

namespace Stewart\Contracts\Registry;

final readonly class AreaId extends RegistryId
{
    protected function describeKind(): string
    {
        return 'Area';
    }
}
