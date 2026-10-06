<?php

declare(strict_types=1);

namespace Stewart\Contracts\Registry\Collection;

use Stewart\Contracts\Collection\KeyedCollection;
use Stewart\Contracts\Registry\Label;
use Stewart\Contracts\Registry\LabelId;

/** @extends KeyedCollection<string, Label> */
final readonly class LabelCollection extends KeyedCollection
{
    /** @param iterable<Label> $entries */
    public static function keyedByLabelId(iterable $entries): self
    {
        return self::keyedBy($entries, static fn(Label $entry): string => $entry->labelId->value);
    }

    public function find(LabelId $labelId): ?Label
    {
        return $this->elementAt($labelId->value);
    }
}
