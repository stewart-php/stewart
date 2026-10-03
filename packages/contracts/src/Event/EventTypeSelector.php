<?php

declare(strict_types=1);

namespace Stewart\Contracts\Event;

use Stewart\Contracts\Exception\SelectorException;
use Stewart\Contracts\Exception\StateException;
use Stewart\Contracts\Selector\Collection\SelectorCollection;
use Stewart\Contracts\Selector\Selector;

final readonly class EventTypeSelector
{
    private function __construct(public Selector $selector) {}

    /** @throws StateException|SelectorException */
    public static function fromSpec(string|Selector|SelectorCollection $spec): self
    {
        $selector = Selector::fromSpec($spec);

        if ($selector->hasExactPattern(HaEvent::STATE_CHANGED)) {
            throw StateException::stateChangedViaEvents(HaEvent::STATE_CHANGED);
        }

        return new self($selector);
    }
}
