<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Stewart\Contracts\Schedule\ScheduledRun;
use Stewart\Runtime\Dispatch\DispatchListener;
use Stewart\Runtime\Dispatch\RegisteredSubscription;
use Stewart\Runtime\Lifecycle\AppFailurePhase;
use Stewart\Runtime\Logging\EveryNthOccurrence;
use Stewart\Runtime\Schedule\ScheduleListener;
use Stewart\Runtime\Schedule\ScheduleOrigin;
use Throwable;

final readonly class AppActivityRecorder implements DispatchListener, ScheduleListener
{
    private EveryNthOccurrence $dropReports;

    public function __construct(
        private AppFailureReporter $failures,
        private AppActivityCounters $activityCounters,
        private WorkerLogger $logger,
    ) {
        $this->dropReports = EveryNthOccurrence::forRepeatedWarnings();
    }

    public function handlerFailed(RegisteredSubscription $subscription, Throwable $error): void
    {
        $origin = \sprintf('subscription %s (%s)', $subscription->id, $subscription->describeMatch());

        $this->failures->report($subscription->scope, AppFailurePhase::Handler, $error, $origin);
    }

    public function eventDelivered(RegisteredSubscription $subscription): void
    {
        $this->activityCounters->findOrCreateActivityForScope($subscription->scope)->recordDeliveredEvent();
    }

    public function eventDropped(RegisteredSubscription $subscription, int $droppedSoFar): void
    {
        $this->activityCounters->findOrCreateActivityForScope($subscription->scope)->recordDroppedEvent();

        if (!$this->dropReports->includesOccurrence($droppedSoFar)) {
            return;
        }

        $this->logger->forScope($subscription->scope)->warning('Handler is falling behind; dropping its oldest events', [
            'subscription' => $subscription->id->value,
            'selector' => $subscription->describeMatch(),
            'dropped' => $droppedSoFar,
        ]);
    }

    public function scheduledRunStarted(ScheduleOrigin $origin, ScheduledRun $run): void
    {
        $this->activityCounters->findOrCreateActivityForScope($origin->scope)->recordScheduleRun();
    }

    public function scheduledRunFailed(ScheduleOrigin $origin, Throwable $error): void
    {
        $this->failures->report($origin->scope, AppFailurePhase::Handler, $error, $origin->label());
    }

    public function scheduleFailed(ScheduleOrigin $origin, Throwable $error): void
    {
        $this->failures->report($origin->scope, AppFailurePhase::Schedule, $error, $origin->label());
    }
}
