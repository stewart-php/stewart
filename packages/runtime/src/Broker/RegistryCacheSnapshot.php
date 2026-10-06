<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Runtime\Ipc\Message\RegistrySnapshot;
use Stewart\Runtime\Ipc\Wire\RegistryFragment;

final readonly class RegistryCacheSnapshot
{
    public function __construct(
        public RegistryFragment $registry,
        public int $revision,
    ) {}

    public function toRegistrySnapshot(): RegistrySnapshot
    {
        return new RegistrySnapshot($this->registry, $this->revision);
    }
}
