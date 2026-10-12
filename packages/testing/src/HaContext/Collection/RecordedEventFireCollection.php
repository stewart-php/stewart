<?php

declare(strict_types=1);

namespace Stewart\Testing\HaContext\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Testing\HaContext\RecordedEventFire;

/** @extends ListCollection<RecordedEventFire> */
final readonly class RecordedEventFireCollection extends ListCollection
{
    public function withRecordedFire(RecordedEventFire $fire): self
    {
        return $this->withAppendedElement($fire);
    }
}
