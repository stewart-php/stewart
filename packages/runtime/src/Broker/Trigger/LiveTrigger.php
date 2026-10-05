<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Trigger;

use Stewart\Contracts\Trigger\TriggerSpec;

final class LiveTrigger
{
    public private(set) ?int $haSubscriptionId = null;

    public function __construct(public readonly TriggerSpec $spec) {}

    public function recordHaSubscriptionId(int $haSubscriptionId): void
    {
        $this->haSubscriptionId = $haSubscriptionId;
    }

    public function forgetHaSubscriptionId(): void
    {
        $this->haSubscriptionId = null;
    }
}
