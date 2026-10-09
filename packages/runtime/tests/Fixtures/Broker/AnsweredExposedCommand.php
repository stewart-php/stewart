<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broker;

use Stewart\Runtime\Broker\Exposure\ExposedEntityCommand;

final readonly class AnsweredExposedCommand
{
    public function __construct(
        public ExposedEntityCommand $command,
        public ?string $rejection,
    ) {}

    public function isAccepted(): bool
    {
        return $this->rejection === null;
    }
}
