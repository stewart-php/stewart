<?php

declare(strict_types=1);

namespace Stewart\Testing\HaContext\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Testing\HaContext\RecordedServiceCall;

/** @extends ListCollection<RecordedServiceCall> */
final readonly class RecordedServiceCallCollection extends ListCollection
{
    /** @param iterable<RecordedServiceCall> $calls */
    public static function fromCalls(iterable $calls): self
    {
        return self::fromList($calls);
    }

    public function withRecordedCall(RecordedServiceCall $call): self
    {
        return $this->withAppendedElement($call);
    }
}
