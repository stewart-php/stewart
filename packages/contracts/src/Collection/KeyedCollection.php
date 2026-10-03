<?php

declare(strict_types=1);

namespace Stewart\Contracts\Collection;

use Closure;

/**
 * @template TKey of array-key
 * @template T
 * @extends TypedCollection<TKey, T>
 */
abstract readonly class KeyedCollection extends TypedCollection
{
    /**
     * @param iterable<T> $elements
     * @param Closure(T): TKey $keyOf
     */
    protected static function keyedBy(iterable $elements, Closure $keyOf): static
    {
        $keyed = [];

        foreach ($elements as $element) {
            $keyed[$keyOf($element)] = $element;
        }

        return new static($keyed);
    }

    /** @param array<TKey, T> $elementsByKey */
    protected static function fromElementsByKey(array $elementsByKey): static
    {
        return new static($elementsByKey);
    }

    /**
     * @param TKey $key
     * @param T $element
     */
    protected function withElementAt(int|string $key, mixed $element): static
    {
        $elements = $this->elements();
        $elements[$key] = $element;

        return new static($elements);
    }

    /** @param TKey $key */
    protected function withoutKey(int|string $key): static
    {
        $elements = $this->elements();
        unset($elements[$key]);

        return new static($elements);
    }

    /**
     * @param TKey $key
     * @return T|null
     */
    protected function elementAt(int|string $key): mixed
    {
        return $this->elements()[$key] ?? null;
    }

    /** @param TKey $key */
    protected function hasKey(int|string $key): bool
    {
        return \array_key_exists($key, $this->elements());
    }

    /**
     * @param array<TKey, T> $elements
     * @return array<TKey, T>
     */
    protected function arrangeElements(array $elements): array
    {
        return $elements;
    }
}
