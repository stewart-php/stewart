<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Worker;

use Stewart\Runtime\Dispatch\DispatchListener;
use Stewart\Runtime\Dispatch\LocalDispatcher;
use Stewart\Runtime\Dispatch\RegisteredSubscription;
use Stewart\Runtime\Dispatch\SubscriptionListener;
use Stewart\Runtime\Scope\ScopeLifecycle;
use Throwable;

final class RecordingDispatchListener implements DispatchListener, SubscriptionListener
{
    /** @var list<string> */
    public array $registered = [];

    /** @var list<string> */
    public array $cancelled = [];

    /** @var list<Throwable> */
    public array $failures = [];

    /** @var list<int> */
    public array $dropReports = [];

    /** @var list<string> */
    public array $delivered = [];

    public static function createDispatcher(string $processId, int $queueLimit, ScopeLifecycle $scopes = new ScopeLifecycle()): LocalDispatcher
    {
        $listener = new self();

        return new LocalDispatcher($processId, $queueLimit, $listener, $listener, $scopes);
    }

    public function subscriptionRegistered(RegisteredSubscription $subscription): void
    {
        $this->registered[] = $subscription->id->value;
    }

    public function subscriptionCancelled(RegisteredSubscription $subscription): void
    {
        $this->cancelled[] = $subscription->id->value;
    }

    public function handlerFailed(RegisteredSubscription $subscription, Throwable $error): void
    {
        $this->failures[] = $error;
    }

    public function eventDropped(RegisteredSubscription $subscription, int $droppedSoFar): void
    {
        $this->dropReports[] = $droppedSoFar;
    }

    public function eventDelivered(RegisteredSubscription $subscription): void
    {
        $this->delivered[] = $subscription->id->value;
    }
}
