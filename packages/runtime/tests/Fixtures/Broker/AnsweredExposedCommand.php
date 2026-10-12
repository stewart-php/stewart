<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broker;

final readonly class AnsweredExposedCommand
{
    public function __construct(
        public string $commandId,
        public ?string $rejection,
    ) {}

    public function isAccepted(): bool
    {
        return $this->rejection === null;
    }
}
