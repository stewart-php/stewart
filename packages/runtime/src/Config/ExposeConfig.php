<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Stewart\Client\Component\ComponentInstance;
use Stewart\Runtime\Exception\ConfigurationException;

final readonly class ExposeConfig
{
    public function __construct(public ComponentInstance $instance) {}

    /** @throws ConfigurationException */
    public static function fromSection(ConfigSection $expose): self
    {
        return new self($expose->readParsedValue('instance', ComponentInstance::parse(...)));
    }
}
