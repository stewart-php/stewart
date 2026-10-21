<?php

declare(strict_types=1);

namespace Stewart\Runtime\Dispatch;

use Throwable;

interface DispatchListener
{
    public function handlerFailed(RegisteredSubscription $subscription, Throwable $error): void;

    public function eventDropped(RegisteredSubscription $subscription, int $droppedSoFar): void;

    public function eventReceived(RegisteredSubscription $subscription, object $event): void;

    public function handlerCalled(RegisteredSubscription $subscription): void;

    public function eventDelivered(RegisteredSubscription $subscription): void;

    public function eventSuppressed(RegisteredSubscription $subscription, ?object $event): void;
}
