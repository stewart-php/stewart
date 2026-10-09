<?php

declare(strict_types=1);

namespace Stewart\Runtime\Container;

use Stewart\Contracts\Identity\StewartIdentity;
use Stewart\Contracts\Sun\SunCalendar;
use Stewart\Contracts\Time\Clock;
use Stewart\Runtime\App\GeneratedRoots;
use Stewart\Runtime\Schedule\WorkerScheduler;
use Stewart\Runtime\Worker\WorkerEntityExposure;
use Stewart\Runtime\Worker\WorkerHaContext;
use Stewart\Runtime\Worker\WorkerLogger;
use Stewart\Runtime\Worker\WorkerMqtt;
use Stewart\Store\Stores;
use Stewart\Support\Time\Deadlines;

final readonly class AppRuntimeServices
{
    public function __construct(
        public WorkerLogger $logger,
        public Clock $clock,
        public Deadlines $deadlines,
        public WorkerScheduler $scheduler,
        public WorkerHaContext $context,
        public Stores $stores,
        public WorkerMqtt $mqtt,
        public WorkerEntityExposure $exposure,
        public StewartIdentity $identity,
        public SunCalendar $sunCalendar,
        public ?GeneratedRoots $generated = null,
    ) {}
}
