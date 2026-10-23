<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Worker;

use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Dispatch\LocalDispatcher;
use Stewart\Runtime\Ipc\Transport;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Registry\RegistryCache;
use Stewart\Runtime\State\StateCache;
use Stewart\Runtime\Tests\Fixtures\Ipc\TestBootstrap;
use Stewart\Runtime\Worker\AppActivityCounters;
use Stewart\Runtime\Worker\ConnectionStatus;
use Stewart\Runtime\Worker\Context\ActionCaller;
use Stewart\Runtime\Worker\Context\DispatchStreams;
use Stewart\Runtime\Worker\Context\EventFirer;
use Stewart\Runtime\Worker\Context\HistoryReader;
use Stewart\Runtime\Worker\Context\TopicPublisher;
use Stewart\Runtime\Worker\CorrelationIdSequence;
use Stewart\Runtime\Worker\PendingRequests;
use Stewart\Runtime\Worker\WorkerHaContext;
use Stewart\Support\State\StewartIdentity;
use Stewart\Testing\Time\ManualTimers;

final class WorkerHaContextFixture
{
    private function __construct() {}

    public static function createWorkerHaContext(
        Transport $transport,
        ResourceScope $scope,
        ManualTimers $timers = new ManualTimers(),
        StateCache $stateCache = new StateCache(),
        ?LocalDispatcher $dispatcher = null,
        PendingRequests $pending = new PendingRequests(new CorrelationIdSequence(new WorkerId(0))),
        ?Duration $callTimeout = null,
        AppActivityCounters $activityCounters = new AppActivityCounters(),
        ConnectionStatus $connection = new ConnectionStatus(),
        RegistryCache $registry = new RegistryCache(),
        StewartIdentity $identity = new StewartIdentity(null),
    ): WorkerHaContext {
        return new WorkerHaContext(
            $stateCache,
            $registry,
            $connection,
            new ActionCaller($transport, $pending, $connection, $timers, TestBootstrap::createWorkerSettings(callTimeout: $callTimeout ?? Duration::seconds(1))),
            new HistoryReader($transport, $pending, $connection, $timers, $timers->clock, TestBootstrap::createWorkerSettings(callTimeout: $callTimeout ?? Duration::seconds(1))),
            new EventFirer($transport, $pending, $connection, $timers, TestBootstrap::createWorkerSettings(callTimeout: $callTimeout ?? Duration::seconds(1))),
            new DispatchStreams($dispatcher ?? RecordingDispatchListener::createDispatcher(10, $registry), $timers, $stateCache, $registry),
            new TopicPublisher($transport, $timers->clock, $activityCounters),
            $identity,
            $timers->clock,
            $scope,
        );
    }
}
