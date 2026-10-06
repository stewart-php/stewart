<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Stewart\Runtime\Exception\ConfigurationException;

final readonly class HttpConfig
{
    public function __construct(public ?HttpListenAddress $listen) {}

    /** @throws ConfigurationException */
    public static function fromSection(ConfigSection $http): self
    {
        $listen = $http->findString('listen');

        return new self(
            listen: $listen === null || strcasecmp(trim($listen), StewartConfigSchema::OFF) === 0
                ? null
                : $http->readParsedValue('listen', HttpListenAddress::parse(...)),
        );
    }
}
