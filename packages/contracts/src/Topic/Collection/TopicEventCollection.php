<?php

declare(strict_types=1);

namespace Stewart\Contracts\Topic\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Contracts\Topic\TopicEvent;

/** @extends ListCollection<TopicEvent> */
final readonly class TopicEventCollection extends ListCollection
{
    public function withTopicEvent(TopicEvent $event): self
    {
        return $this->withAppendedElement($event);
    }
}
