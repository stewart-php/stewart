<?php

declare(strict_types=1);

namespace Stewart\Testing\HaContext\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Testing\HaContext\RecordedEventFire;

/** @extends ListCollection<RecordedEventFire> */
final readonly class RecordedEventFireCollection extends ListCollection
{
    /** @param iterable<RecordedEventFire> $fires */
    public static function fromFires(iterable $fires): self
    {
        return self::fromList($fires);
    }

    public function withRecordedFire(RecordedEventFire $fire): self
    {
        return $this->withAppendedElement($fire);
    }
}
