<?php

declare(strict_types=1);

namespace Stewart\Runtime\Dispatch;

use Closure;

final readonly class QueuedWork
{
    /**
     * @param Closure(object): void|Closure(): void $handler
     * @param ?Closure(QueueDiscardReason): void $onDiscarded
     */
    private function __construct(
        public ?object $event,
        private Closure $handler,
        private ?Closure $onDiscarded = null,
    ) {}

    /**
     * @param Closure(object): void $handler
     * @param ?Closure(QueueDiscardReason): void $onDiscarded
     */
    public static function forDroppableEvent(object $event, Closure $handler, ?Closure $onDiscarded = null): self
    {
        return new self($event, $handler, $onDiscarded);
    }

    /** @param Closure(): void $emission */
    public static function forStreamEmission(Closure $emission): self
    {
        return new self(null, $emission);
    }

    public function withEvent(object $event): self
    {
        return new self($event, $this->handler, $this->onDiscarded);
    }

    public function isDroppable(): bool
    {
        return $this->event !== null;
    }

    public function discard(QueueDiscardReason $reason): void
    {
        if ($this->onDiscarded !== null) {
            ($this->onDiscarded)($reason);
        }
    }

    public function __invoke(): void
    {
        if ($this->event === null) {
            ($this->handler)();

            return;
        }

        ($this->handler)($this->event);
    }
}
