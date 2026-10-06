<?php

declare(strict_types=1);

namespace Stewart\Contracts\Registry;

final readonly class LabelId extends RegistryId
{
    protected function describeKind(): string
    {
        return 'Label';
    }
}
