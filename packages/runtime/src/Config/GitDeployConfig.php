<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Exception\ConfigurationException;

final readonly class GitDeployConfig
{
    public function __construct(
        public OptionalDuration $poll,
        public Duration $prepareTimeout,
    ) {}

    /** @throws ConfigurationException */
    public static function fromSection(ConfigSection $git): self
    {
        $oneSecond = Duration::seconds(1);

        return new self(
            poll: $git->readOptionalDuration('poll', $oneSecond),
            prepareTimeout: $git->readDuration('prepare_timeout', $oneSecond),
        );
    }
}
