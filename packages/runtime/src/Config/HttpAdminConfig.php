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
        $listen = $admin->findString('listen');
        $token = $admin->findString('token');

        return new self(
            listen: $listen === null || strcasecmp(trim($listen), StewartConfigSchema::OFF) === 0 ? null : $admin->readParsedValue('listen', HttpListenAddress::parse(...)),
            token: $token === '' ? null : $token,
        );
    }
}
