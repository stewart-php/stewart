<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure\Command;

use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\State\EventContext;

interface ExposedCommand
{
    public function getContext(): EventContext;

    public function getRequestedState(): ?ExposedState;
}
