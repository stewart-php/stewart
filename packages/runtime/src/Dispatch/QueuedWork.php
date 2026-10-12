<?php

declare(strict_types=1);

namespace Stewart\Runtime\Dispatch;

use Closure;

final readonly class QueuedWork
{
    /**
     * @param Closure(): void $run
     * @param ?Closure(QueueDiscardReason): void $onDiscarded
     */
    private function __construct(
        public bool $droppable,
        private Closure $run,
        private ?Closure $onDiscarded = null,
    ) {}

    /**
     * @param Closure(): void $run
     * @param ?Closure(QueueDiscardReason): void $onDiscarded
     */
    public static function forDroppableEvent(Closure $run, ?Closure $onDiscarded = null): self
    {
        return new self(true, $run, $onDiscarded);
    }

    /** @param Closure(): void $run */
    public static function forStreamEmission(Closure $run): self
    {
        return new self(false, $run);
    }

    public function discard(QueueDiscardReason $reason): void
    {
        if ($this->onDiscarded !== null) {
            ($this->onDiscarded)($reason);
        }
    }

    public function __invoke(): void
    {
        ($this->run)();
    }
}
