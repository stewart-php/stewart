<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Frame;

use SensitiveParameter;
use Stewart\Runtime\Control\Protocol\ControlProtocol;

final readonly class Hello implements ClientFrame
{
    public function __construct(
        #[SensitiveParameter]
        public string $token,
        public string $client,
        public int $protocol = ControlProtocol::VERSION,
    ) {}
}
