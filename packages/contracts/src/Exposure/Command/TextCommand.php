<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure\Command;

use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\State\EventContext;

final readonly class TextCommand implements ExposedCommand
{
    public function __construct(
        public string $value,
        public EventContext $context,
    ) {}

    public function getContext(): EventContext
    {
        return $this->context;
    }

    public function getRequestedState(): ExposedState
    {
        return new ExposedState($this->value);
    }
}
