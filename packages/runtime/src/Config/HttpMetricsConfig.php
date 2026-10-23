<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use SensitiveParameter;
use Stewart\Runtime\Exception\ConfigurationException;

final readonly class HttpMetricsConfig
{
    public function __construct(
        public ?HttpListenAddress $listen,
        #[SensitiveParameter]
        public ?string $token,
    ) {}

    /** @throws ConfigurationException */
    public static function fromSection(#[SensitiveParameter] ConfigSection $metrics): self
    {
        return new self(
            listen: $metrics->findSwitchableValue('listen', HttpListenAddress::parse(...)),
            token: $metrics->findSwitchableValue('token', strval(...)),
        );
    }
}
