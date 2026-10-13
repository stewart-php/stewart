<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use SensitiveParameter;
use Stewart\Runtime\Exception\ConfigurationException;

final readonly class ControlConfig
{
    public function __construct(
        public ?ControlAddress $listen,
        #[SensitiveParameter]
        public ?string $token,
    ) {}

    /** @throws ConfigurationException */
    public static function fromSection(#[SensitiveParameter] ConfigSection $control): self
    {
        return new self(
            listen: $control->findSwitchableValue('listen', ControlAddress::parse(...)),
            token: $control->findSwitchableValue('token', strval(...)),
        );
    }
}
