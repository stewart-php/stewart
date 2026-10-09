<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Stewart\Client\Component\ComponentInstance;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Exception\ConfigurationException;

final readonly class ExposeConfig
{
    private const int LONGEST_COMMAND_TIMEOUT_THE_COMPONENT_ACCEPTS_SECONDS = 300;

    public function __construct(
        public ComponentInstance $instance,
        public Duration $commandTimeout,
        public bool $prune = true,
    ) {}

    /** @throws ConfigurationException */
    public static function fromSection(ConfigSection $expose): self
    {
        return new self(
            $expose->readParsedValue('instance', ComponentInstance::parse(...)),
            $expose->readDuration('command_timeout', Duration::milliseconds(1), Duration::seconds(self::LONGEST_COMMAND_TIMEOUT_THE_COMPONENT_ACCEPTS_SECONDS)),
            $expose->readBool('prune'),
        );
    }
}
