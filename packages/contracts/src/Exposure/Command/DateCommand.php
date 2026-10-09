<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure\Command;

use DateTimeImmutable;
use Stewart\Contracts\Exposure\CalendarStateFormat;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\State\EventContext;

final readonly class DateCommand implements ExposedCommand
{
    public function __construct(
        public DateTimeImmutable $value,
        public EventContext $context,
    ) {}

    public function getContext(): EventContext
    {
        return $this->context;
    }

    public function getRequestedState(): ExposedState
    {
        return new ExposedState(CalendarStateFormat::formatDate($this->value));
    }
}
