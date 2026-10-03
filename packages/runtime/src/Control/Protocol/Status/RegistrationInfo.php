<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Status;

use Stewart\Runtime\Model\SubscriptionKind;

final readonly class RegistrationInfo
{
    public function __construct(
        public string $subscriptionId,
        public int $workerId,
        public string $appId,
        public SubscriptionKind $kind,
        public string $selector,
        public bool $exact,
    ) {}
}
