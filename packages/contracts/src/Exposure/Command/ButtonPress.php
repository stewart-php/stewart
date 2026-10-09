<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure\Command;

use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\State\EventContext;

final readonly class ButtonPress implements ExposedCommand
{
    public function __construct(public EventContext $context) {}

    public function getContext(): EventContext
    {
        return $this->context;
    }

    public function getRequestedState(): ?ExposedState
    {
        return null;
    }
}
