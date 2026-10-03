<?php

declare(strict_types=1);

namespace Stewart\Runtime\Dispatch;

use Throwable;

interface DispatchListener
{
    public function handlerFailed(RegisteredSubscription $subscription, Throwable $error): void;

    public function eventDropped(RegisteredSubscription $subscription, int $droppedSoFar): void;

    public function eventDelivered(RegisteredSubscription $subscription): void;
}
