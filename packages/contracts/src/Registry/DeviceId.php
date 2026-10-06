<?php

declare(strict_types=1);

namespace Stewart\Contracts\Registry;

final readonly class DeviceId extends RegistryId
{
    protected function describeKind(): string
    {
        return 'Device';
    }
}
