<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broker;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Timers;
use Stewart\Runtime\Broker\OutboxLimits;
use Stewart\Runtime\Broker\RestartBudget;
use Stewart\Runtime\Broker\WorkerMessageReader;
use Stewart\Runtime\Broker\WorkerPool;
use Stewart\Runtime\Broker\WorkerProbeSequence;
use Stewart\Runtime\Broker\WorkerRestartPolicy;
use Stewart\Runtime\Broker\WorkerSlotRegistry;
use Stewart\Runtime\Broker\WorkerSpawner;
use Stewart\Runtime\Broker\WorkerWatchdog;
use Stewart\Runtime\Config\SupervisionConfig;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigFixture;
use Stewart\Runtime\Time\SystemClock;
use Stewart\Support\Time\Deadlines;
use Stewart\Support\Time\RevoltTimers;
use Stewart\Testing\Time\ManualTimers;

final readonly class WorkerPoolFixture
{
    private function __construct(
        public WorkerPool $pool,
        public WorkerSlotRegistry $slots,
        public WorkerWatchdog $watchdog,
        public WorkerRestartPolicy $restartPolicy,
        public WorkerProbeSequence $probes,
        public Clock $clock,
    ) {}

    public static function createWorkerPool(
        WorkerSpawner $spawner,
        ?SupervisionConfig $supervision = null,
        Timers&Deadlines $timers = new RevoltTimers(),
        LoggerInterface $logger = new NullLogger(),
        OutboxLimits $outboxLimits = new OutboxLimits(10, 256),
    ): self {
        $clock = $timers instanceof ManualTimers ? $timers->clock : SystemClock::inUtc();
        $supervision ??= ConfigFixture::createSupervisionConfig(['ping_interval' => 'off']);
        $slots = new WorkerSlotRegistry();
        $probes = new WorkerProbeSequence();
        $restartPolicy = new WorkerRestartPolicy(new RestartBudget($supervision->restartAttempts, $supervision->restartWindow), $supervision, $clock);

        return new self(
            new WorkerPool($spawner, $slots, $restartPolicy, $probes, $supervision, new WorkerMessageReader($logger), $logger, $outboxLimits, $timers, $clock),
            $slots,
            new WorkerWatchdog($slots, $probes, $supervision, $timers, $clock, $logger),
            $restartPolicy,
            $probes,
            $clock,
        );
    }
}
