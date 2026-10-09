<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure\Command;

use Stewart\Contracts\Exposure\CalendarStateFormat;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Schedule\TimeOfDay;
use Stewart\Contracts\State\EventContext;

final readonly class TimeCommand implements ExposedCommand
{
    public function __construct(
        public TimeOfDay $value,
        public EventContext $context,
    ) {}

    public function getContext(): EventContext
    {
        return $this->context;
    }

    public function getRequestedState(): ExposedState
    {
        return new ExposedState(CalendarStateFormat::formatTime($this->value));
    }
}
