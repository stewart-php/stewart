<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Worker;

use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Dispatch\DispatchListener;
use Stewart\Runtime\Dispatch\LocalDispatcher;
use Stewart\Runtime\Dispatch\RegisteredSubscription;
use Stewart\Runtime\Dispatch\SubscriptionListener;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Registry\RegistryCache;
use Stewart\Runtime\Scope\ScopeLifecycle;
use Stewart\Runtime\Tests\Fixtures\Ipc\TestBootstrap;
use Stewart\Testing\Time\VirtualClock;
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

    /** @var list<object> */
    public array $received = [];

    /** @var list<string> */
    public array $handlerCalls = [];

    /** @var list<string> */
    public array $delivered = [];

    /** @var list<string> */
    public array $suppressed = [];

    /** @var list<?object> */
    public array $suppressedEvents = [];

    public static function createDispatcher(int $queueLimit, RegistryCache $registry = new RegistryCache(), ScopeLifecycle $scopes = new ScopeLifecycle(), Clock $clock = new VirtualClock()): LocalDispatcher
    {
        $listener = new self();

        return new LocalDispatcher(new WorkerId(0), TestBootstrap::createWorkerSettings(subscriptionBuffer: $queueLimit), $listener, $listener, $scopes, $registry, $clock);
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

    public function eventReceived(RegisteredSubscription $subscription, object $event): void
    {
        $this->received[] = $event;
    }

    public function handlerCalled(RegisteredSubscription $subscription, Instant $calledAt): void
    {
        $this->handlerCalls[] = $subscription->id->value;
    }

    public function eventDelivered(RegisteredSubscription $subscription): void
    {
        $this->delivered[] = $subscription->id->value;
    }

    public function eventSuppressed(RegisteredSubscription $subscription, ?object $event): void
    {
        $this->suppressed[] = $subscription->id->value;
        $this->suppressedEvents[] = $event;
    }
}
