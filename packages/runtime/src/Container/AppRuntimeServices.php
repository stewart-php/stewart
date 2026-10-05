<?php

declare(strict_types=1);

namespace Stewart\Runtime\Container;

use Stewart\Contracts\Identity\StewartIdentity;
use Stewart\Contracts\Time\Clock;
use Stewart\Runtime\App\GeneratedRoots;
use Stewart\Runtime\Schedule\WorkerScheduler;
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
        public StewartIdentity $identity,
        public ?GeneratedRoots $generated = null,
    ) {}
}
