<?php

declare(strict_types=1);

namespace Stewart\Contracts\Collection;

/**
 * @template T
 * @extends TypedCollection<int, T>
 */
abstract readonly class ListCollection extends TypedCollection
{
    /** @param iterable<T> $elements */
    protected static function fromList(iterable $elements): static
    {
        $list = [];

        foreach ($elements as $element) {
            $list[] = $element;
        }

        return new static($list);
    }

    /** @param T $element */
    protected function withAppendedElement(mixed $element): static
    {
        return new static([...$this->elements(), $element]);
    }

    /**
     * @param array<int, T> $elements
     * @return list<T>
     */
    protected function arrangeElements(array $elements): array
    {
        return array_values($elements);
    }
}
