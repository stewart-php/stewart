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
        $listen = $control->findString('listen');
        $token = $control->findString('token');

        return new self(
            listen: $listen === null || strcasecmp(trim($listen), StewartConfigSchema::OFF) === 0 ? null : $control->readParsedValue('listen', ControlAddress::parse(...)),
            token: $token === '' ? null : $token,
        );
    }
}
