<?php

declare(strict_types=1);

namespace Stewart\Testing\HaContext\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Testing\HaContext\RecordedHistoryQuery;

/** @extends ListCollection<RecordedHistoryQuery> */
final readonly class RecordedHistoryQueryCollection extends ListCollection
{
    /** @param iterable<RecordedHistoryQuery> $queries */
    public static function fromQueries(iterable $queries): self
    {
        return self::fromList($queries);
    }

    public function withRecordedQuery(RecordedHistoryQuery $query): self
    {
        return $this->withAppendedElement($query);
    }
}
