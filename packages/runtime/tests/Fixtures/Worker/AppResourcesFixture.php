<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Worker;

use Stewart\Runtime\Dispatch\LocalDispatcher;
use Stewart\Runtime\Schedule\ScheduleContext;
use Stewart\Runtime\Schedule\ScheduleListener;
use Stewart\Runtime\Schedule\ScheduleRegistry;
use Stewart\Runtime\Schedule\TriggerFactory;
use Stewart\Runtime\Scope\ScopeLifecycle;
use Stewart\Runtime\Worker\AppResources;
use Stewart\Testing\Time\ManualTimers;

final readonly class AppResourcesFixture
{
    public ScopeLifecycle $scopes;

    public LocalDispatcher $dispatcher;

    public ScheduleRegistry $schedules;

    public AppResources $resources;

    public function __construct(
        public ManualTimers $timers = new ManualTimers(),
        int $subscriptionQueueLimit = 100,
        ScheduleListener $scheduleListener = new RecordingScheduleListener(),
    ) {
        $this->scopes = new ScopeLifecycle();
        $this->dispatcher = RecordingDispatchListener::createDispatcher('w0', $subscriptionQueueLimit, scopes: $this->scopes);
        $this->schedules = new ScheduleRegistry(
            'w0',
            new ScheduleContext($timers, $timers->clock, new InlineHandlerRunner(), $scheduleListener),
            new TriggerFactory($timers->clock),
            $this->scopes,
        );
        $this->resources = new AppResources($this->scopes, $this->dispatcher, $this->schedules);
    }
}
