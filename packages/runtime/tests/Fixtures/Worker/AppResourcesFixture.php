<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Worker;

use Stewart\Runtime\Dispatch\LocalDispatcher;
use Stewart\Runtime\Registry\RegistryCache;
use Stewart\Runtime\Schedule\EntityTimeScheduler;
use Stewart\Runtime\Schedule\ScheduleContext;
use Stewart\Runtime\Schedule\ScheduleListener;
use Stewart\Runtime\Schedule\ScheduleRegistry;
use Stewart\Runtime\Schedule\TriggerFactory;
use Stewart\Runtime\Scope\ScopeLifecycle;
use Stewart\Runtime\State\StateCache;
use Stewart\Runtime\Worker\AppResources;
use Stewart\Runtime\Worker\Context\DispatchStreams;
use Stewart\Testing\Time\ManualTimers;

final readonly class AppResourcesFixture
{
    public ScopeLifecycle $scopes;

    public LocalDispatcher $dispatcher;

    public ScheduleRegistry $schedules;

    public StateCache $states;

    public EntityTimeScheduler $entityTimes;

    public AppResources $resources;

    public function __construct(
        public ManualTimers $timers = new ManualTimers(),
        int $subscriptionQueueLimit = 100,
        ScheduleListener $scheduleListener = new RecordingScheduleListener(),
    ) {
        $this->scopes = new ScopeLifecycle();
        $registry = new RegistryCache();
        $this->states = new StateCache();
        $this->dispatcher = RecordingDispatchListener::createDispatcher('w0', $subscriptionQueueLimit, $registry, $this->scopes);
        $this->schedules = new ScheduleRegistry(
            'w0',
            new ScheduleContext($timers, $timers->clock, new InlineHandlerRunner(), $scheduleListener),
            new TriggerFactory($timers->clock),
            $this->scopes,
        );
        $this->entityTimes = new EntityTimeScheduler($this->schedules, $this->states, new DispatchStreams($this->dispatcher, $timers, $this->states, $registry), $timers->clock);
        $this->resources = new AppResources($this->scopes, $this->dispatcher, $this->schedules, $this->entityTimes);
    }
}
