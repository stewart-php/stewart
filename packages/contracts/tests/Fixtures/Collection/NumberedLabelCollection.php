<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Fixtures\Collection;

use Stewart\Contracts\Collection\KeyedCollection;

/** @extends KeyedCollection<int, NumberedLabel> */
final readonly class NumberedLabelCollection extends KeyedCollection
{
    /** @param iterable<NumberedLabel> $labels */
    public static function keyedByNumber(iterable $labels): self
    {
        return self::keyedBy($labels, static fn(NumberedLabel $label): int => $label->number);
    }

    public function find(int $number): ?NumberedLabel
    {
        return $this->elementAt($number);
    }

    public function withoutNumber(int $number): self
    {
        return $this->withoutKey($number);
    }
}
