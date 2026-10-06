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
use Stewart\Runtime\Tests\Fixtures\Worker\RecordingDispatchListener;
use Stewart\Runtime\Worker\Context\DispatchStreams;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;

final class QueuedStreamFixture
{
    public readonly ManualTimers $timers;

    public readonly RecordingDispatchListener $listener;

    private readonly LocalDispatcher $dispatcher;

    private readonly DispatchStreams $streams;

    private readonly ResourceScope $scope;

    public function __construct(int $subscriptionQueueLimit = 100)
    {
        $this->timers = new ManualTimers();
        $this->listener = new RecordingDispatchListener();
        $this->scope = ResourceScope::forApp(new AppId('queued-streams'));

        $scopes = new ScopeLifecycle();
        $scopes->activateScope($this->scope);

        $this->dispatcher = new LocalDispatcher('w0', $subscriptionQueueLimit, $this->listener, $this->listener, $scopes, new RegistryCache());
        $this->streams = new DispatchStreams($this->dispatcher, $this->timers);
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
