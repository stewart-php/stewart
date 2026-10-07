<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Dispatch;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\StateChangeStream;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Dispatch\LocalDispatcher;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Registry\RegistryCache;
use Stewart\Runtime\Scope\ScopeLifecycle;
use Stewart\Runtime\State\StateCache;
use Stewart\Runtime\Tests\Fixtures\Worker\RecordingDispatchListener;
use Stewart\Runtime\Worker\Context\DispatchStreams;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;

final class QueuedStreamFixture
{
    public readonly ManualTimers $timers;

    public readonly RecordingDispatchListener $listener;

    public readonly StateCache $states;

    private readonly LocalDispatcher $dispatcher;

    private readonly DispatchStreams $streams;

    private readonly ResourceScope $scope;

    private readonly ScopeLifecycle $scopes;

    public function __construct(int $subscriptionQueueLimit = 100)
    {
        $this->timers = new ManualTimers();
        $this->listener = new RecordingDispatchListener();
        $this->states = new StateCache();
        $this->scope = ResourceScope::forApp(new AppId('queued-streams'));

        $this->scopes = new ScopeLifecycle();
        $this->scopes->activateScope($this->scope);

        $registry = new RegistryCache();
        $this->dispatcher = new LocalDispatcher('w0', $subscriptionQueueLimit, $this->listener, $this->listener, $this->scopes, $registry);
        $this->streams = new DispatchStreams($this->dispatcher, $this->timers, $this->states, $registry);
    }

    public function watchStateChanges(string $selector): StateChangeStream
    {
        return $this->streams->watchStateChanges($this->scope, Selector::fromSpec($selector));
    }

    public function dispatchStateChange(string $entityId, string $from, string $to): void
    {
        $id = new EntityId($entityId);

        $this->dispatcher->dispatchStateChange(new StateChange($id, new EntityState($id, $from), new EntityState($id, $to)));
    }

    public function seedState(string $entityId, string $state): void
    {
        $id = new EntityId($entityId);

        $this->states->applyChange(new StateChange($id, null, new EntityState($id, $state)));
    }

    public function pauseScope(): void
    {
        $this->scopes->pauseScope($this->scope);
        $this->dispatcher->pauseQueuesOf($this->scope);
    }

    public function advanceTime(Duration $by): void
    {
        $this->timers->delay($by);
    }

    public function drainQueues(): void
    {
        EventLoopTicks::settle();
    }

    public function countSubscriptions(): int
    {
        return $this->dispatcher->countFor($this->scope);
    }
}
