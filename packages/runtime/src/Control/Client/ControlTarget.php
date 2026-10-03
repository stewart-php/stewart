<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Client;

use SensitiveParameter;
use Stewart\Runtime\Config\ControlAddress;

final readonly class ControlTarget
{
    public function __construct(
        public ControlAddress $address,
        #[SensitiveParameter]
        public string $token,
    ) {}
}
