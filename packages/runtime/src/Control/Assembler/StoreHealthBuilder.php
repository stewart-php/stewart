<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Assembler;

use Stewart\Runtime\Broker\WorkerSlotRegistry;
use Stewart\Store\StoreHealth;

final readonly class StoreHealthBuilder
{
    public function __construct(
        private WorkerSlotRegistry $slots,
        private bool $storeConfigured,
    ) {}

    public function buildStoreHealth(): ?StoreHealth
    {
        if (!$this->storeConfigured) {
            return null;
        }

        $available = true;
        $newest = null;

        foreach ($this->slots->listLiveHandles() as $handle) {
            $reported = $handle->lastPong?->store;

            if ($reported === null) {
                continue;
            }

            $available = $available && $reported->available;

            if ($reported->lastFailureAt !== null && ($newest?->lastFailureAt === null || $newest->lastFailureAt->isBefore($reported->lastFailureAt))) {
                $newest = $reported;
            }
        }

        return new StoreHealth($available, $newest?->lastFailure, $newest?->lastFailureAt);
    }
}
