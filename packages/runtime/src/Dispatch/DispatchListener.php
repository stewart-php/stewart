<?php

declare(strict_types=1);

namespace Stewart\Runtime\Dispatch;

use Stewart\Contracts\Time\Instant;
use Throwable;

interface DispatchListener
{
    public function handlerFailed(RegisteredSubscription $subscription, Throwable $error): void;

    public function eventDropped(RegisteredSubscription $subscription, int $droppedSoFar): void;

    public function eventReceived(RegisteredSubscription $subscription, object $event): void;

    public function handlerCalled(RegisteredSubscription $subscription, Instant $calledAt): void;

    public function eventDelivered(RegisteredSubscription $subscription): void;

    public function eventSuppressed(RegisteredSubscription $subscription, ?object $event): void;
}
