<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure\Command;

use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\State\EventContext;

final readonly class SwitchCommand implements ExposedCommand
{
    public function __construct(
        public SwitchAction $action,
        public EventContext $context,
    ) {}

    public function isTurnOn(): bool
    {
        return $this->action === SwitchAction::TurnOn;
    }

    public function getContext(): EventContext
    {
        return $this->context;
    }

    public function getRequestedState(): ExposedState
    {
        return new ExposedState($this->isTurnOn());
    }
}
