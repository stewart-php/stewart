<?php

declare(strict_types=1);

namespace Stewart\Contracts\Stream;

use Closure;

/**
 * @template T
 * @extends ComposedStream<T>
 */
final readonly class OperatorStream extends ComposedStream
{
    protected function extendWith(StreamSource $stage): static
    {
        return new static($stage, $this->timers);
    }

    protected function createPartitionKeyReader(): Closure
    {
        return static fn(mixed $event): string => '';
    }
}
