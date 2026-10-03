<?php

declare(strict_types=1);

namespace Stewart\Runtime\Dispatch;

use Closure;

final readonly class QueuedWork
{
    /** @param Closure(): void $run */
    private function __construct(
        public bool $droppable,
        private Closure $run,
    ) {}

    /** @param Closure(): void $run */
    public static function forDroppableEvent(Closure $run): self
    {
        return new self(true, $run);
    }

    /** @param Closure(): void $run */
    public static function forStreamEmission(Closure $run): self
    {
        return new self(false, $run);
    }

    public function __invoke(): void
    {
        ($this->run)();
    }
}
