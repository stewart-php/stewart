<?php

declare(strict_types=1);

namespace Stewart\Contracts\Collection;

use ArrayIterator;
use Closure;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @template TKey of array-key
 * @template T
 * @implements IteratorAggregate<TKey, T>
 */
abstract readonly class TypedCollection implements IteratorAggregate, Countable
{
    /** @param array<TKey, T> $elements */
    final protected function __construct(private array $elements = []) {}

    public static function empty(): static
    {
        return new static();
    }

    public function count(): int
    {
        return \count($this->elements);
    }

    public function isEmpty(): bool
    {
        return $this->elements === [];
    }

    /** @return T|null */
    public function getFirst(): mixed
    {
        $key = array_key_first($this->elements);

        return $key === null ? null : $this->elements[$key];
    }

    /** @return T|null */
    public function getLast(): mixed
    {
        $key = array_key_last($this->elements);

        return $key === null ? null : $this->elements[$key];
    }

    /** @return list<T> */
    public function listValues(): array
    {
        return array_values($this->elements);
    }

    /** @param Closure(T): bool $predicate */
    public function filter(Closure $predicate): static
    {
        return new static($this->arrangeElements(array_filter($this->elements, $predicate)));
    }

    /**
     * @template TResult
     * @param Closure(T): TResult $transform
     * @return list<TResult>
     */
    public function mapToList(Closure $transform): array
    {
        return array_values(array_map($transform, $this->elements));
    }

    /** @param Closure(T): bool $predicate */
    public function containsWhere(Closure $predicate): bool
    {
        return array_any($this->elements, $predicate);
    }

    /**
     * @param Closure(T): bool $predicate
     * @return T|null
     */
    public function findFirstWhere(Closure $predicate): mixed
    {
        return array_find($this->elements, $predicate);
    }

    /** @param Closure(T, T): int $comparator */
    public function sortedBy(Closure $comparator): static
    {
        $sorted = $this->elements;
        uasort($sorted, $comparator);

        return new static($this->arrangeElements($sorted));
    }

    /** @return Traversable<TKey, T> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->elements);
    }

    /**
     * @param array<TKey, T> $elements
     * @return array<TKey, T>
     */
    abstract protected function arrangeElements(array $elements): array;

    /** @return array<TKey, T> */
    protected function elements(): array
    {
        return $this->elements;
    }
}
