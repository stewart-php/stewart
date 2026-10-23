<?php

declare(strict_types=1);

namespace Stewart\Testing\HaContext\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Testing\HaContext\RecordedActionCall;

/** @extends ListCollection<RecordedActionCall> */
final readonly class RecordedActionCallCollection extends ListCollection
{
    public function withRecordedCall(RecordedActionCall $call): self
    {
        return $this->withAppendedElement($call);
    }
}
