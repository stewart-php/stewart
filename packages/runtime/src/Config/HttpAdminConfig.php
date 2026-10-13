<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use SensitiveParameter;
use Stewart\Runtime\Exception\ConfigurationException;

final readonly class HttpAdminConfig
{
    public function __construct(
        public ?HttpListenAddress $listen,
        #[SensitiveParameter]
        public ?string $token,
    ) {}

    /** @throws ConfigurationException */
    public static function fromSection(#[SensitiveParameter] ConfigSection $admin): self
    {
        return new self(
            listen: $admin->findSwitchableValue('listen', HttpListenAddress::parse(...)),
            token: $admin->findSwitchableValue('token', strval(...)),
        );
    }
}
