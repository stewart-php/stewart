<?php

declare(strict_types=1);

namespace Stewart\Testing\Stream;

use Closure;
use Stewart\Contracts\Subscription;

final class PushSubscription implements Subscription
{
    private bool $active = true;

    /** @param Closure(string): void $onCancel */
    public function __construct(
        private readonly string $id,
        private readonly Closure $onCancel,
    ) {}

    public function unsubscribe(): void
    {
        if (!$this->active) {
            return;
        }

        $this->active = false;
        ($this->onCancel)($this->id);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getId(): string
    {
        return $this->id;
    }
}
