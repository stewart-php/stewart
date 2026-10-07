<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Schedule;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\Sun\SunCalendar;
use Stewart\Runtime\Dispatch\LocalDispatcher;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Registry\RegistryCache;
use Stewart\Runtime\Schedule\EntityTimeScheduler;
use Stewart\Runtime\Schedule\ScheduleRegistry;
use Stewart\Runtime\Schedule\WorkerScheduler;
use Stewart\Runtime\State\StateCache;
use Stewart\Runtime\Tests\Fixtures\Worker\RecordingDispatchListener;
use Stewart\Runtime\Worker\Context\DispatchStreams;
use Stewart\Testing\Time\ManualTimers;

final class WorkerSchedulerFixture
{
    private function __construct() {}

    public static function createWorkerScheduler(
        ScheduleRegistry $registry,
        SunCalendar $sunCalendar,
        LoggerInterface $logger,
        ResourceScope $scope,
        ManualTimers $timers = new ManualTimers(),
        StateCache $states = new StateCache(),
        ?LocalDispatcher $dispatcher = null,
        ?EntityTimeScheduler $entityTimes = null,
    ): WorkerScheduler {
        $registryCache = new RegistryCache();
        $streams = new DispatchStreams($dispatcher ?? RecordingDispatchListener::createDispatcher('w0', 10, $registryCache), $timers, $states, $registryCache);
        $entityTimes ??= new EntityTimeScheduler($registry, $states, $streams, $timers->clock);

        return new WorkerScheduler($registry, $sunCalendar, $entityTimes, $logger, $scope);
    }
}
