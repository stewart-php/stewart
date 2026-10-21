<?php

declare(strict_types=1);

namespace Stewart\Runtime\Dispatch;

use Stewart\Contracts\Time\Instant;

final class SubscriptionActivity
{
    public private(set) int $received = 0;

    public private(set) int $handled = 0;

    public private(set) int $dropped = 0;

    public private(set) ?Instant $lastReceivedAt = null;

    public private(set) ?Instant $lastHandledAt = null;

    public function __construct(public readonly RegisteredSubscription $subscription) {}

    public function recordReceived(Instant $at): void
    {
        ++$this->received;
        $this->lastReceivedAt = $at;
    }

    public function recordHandled(Instant $at): void
    {
        ++$this->handled;
        $this->lastHandledAt = $at;
    }

    public function recordDropped(): void
    {
        ++$this->dropped;
    }
}
