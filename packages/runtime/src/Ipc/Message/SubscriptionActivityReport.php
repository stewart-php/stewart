<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Model\SubscriptionKind;

final readonly class SubscriptionActivityReport
{
    public function __construct(
        public SubscriptionId $subscriptionId,
        public SubscriptionKind $kind,
        public string $match,
        public bool $exact,
        public int $received,
        public int $handled,
        public int $dropped,
        public ?Instant $lastReceivedAt,
        public ?Instant $lastHandledAt,
    ) {}
}
