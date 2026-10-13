<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Stewart\Runtime\Exception\ConfigurationException;

final readonly class HttpConfig
{
    public function __construct(
        public ?HttpListenAddress $listen,
        public HttpAdminConfig $admin,
    ) {}

    /** @throws ConfigurationException */
    public static function fromSection(ConfigSection $http): self
    {
        return new self(
            listen: $http->findSwitchableValue('listen', HttpListenAddress::parse(...)),
            admin: HttpAdminConfig::fromSection($http->readSection('admin')),
        );
    }
}
