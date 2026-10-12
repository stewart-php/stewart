<?php

declare(strict_types=1);

namespace Stewart\Support\Wire;

use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\Exception\SelectorException;
use Stewart\Contracts\Exception\StateError;
use Stewart\Contracts\Exception\StateException;
use Stewart\Contracts\Selector\Collection\SelectorCollection;
use Stewart\Contracts\Selector\Selector;

/** @internal */
final readonly class EventTypeSelector
{
    private function __construct(public Selector $selector) {}

    /** @throws StateException|SelectorException */
    public static function fromSpec(string|Selector|SelectorCollection $spec): self
    {
        $selector = Selector::fromSpec($spec);

        if ($selector->hasExactPattern(HaEvent::STATE_CHANGED)) {
            throw StateException::createForReason(StateError::StateChangedViaEvents, eventType: HaEvent::STATE_CHANGED);
        }

        return new self($selector);
    }
}
