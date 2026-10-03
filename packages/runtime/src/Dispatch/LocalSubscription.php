<?php

declare(strict_types=1);

namespace Stewart\Runtime\Dispatch;

use Closure;
use Stewart\Contracts\Subscription;
use Stewart\Runtime\Model\SubscriptionId;

final class LocalSubscription implements Subscription
{
    private bool $active = true;

    /** @param Closure(SubscriptionId): void $onCancel */
    public function __construct(
        private readonly SubscriptionId $id,
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
        return $this->id->value;
    }
}
