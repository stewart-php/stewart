<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Schedule\ScheduledRun;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Topic\TopicEvent;
use Stewart\Contracts\Trigger\TriggerEvent;
use Stewart\Runtime\Dispatch\RegisteredSubscription;
use Stewart\Runtime\Logging\LogVerbosityPolicy;
use Stewart\Runtime\Schedule\ScheduleOrigin;

final readonly class AppTracer
{
    public function __construct(
        private WorkerLogger $logger,
        private LogVerbosityPolicy $verbosity,
    ) {}

    public function traceEventReceived(RegisteredSubscription $subscription, object $event): void
    {
        if ($this->verbosity->isTracing($subscription->scope)) {
            $this->logger->forScope($subscription->scope)->recordTrace('Event received', [...$this->describeSubscription($subscription), ...$this->describeEvent($subscription, $event)]);
        }
    }

    public function traceHandlerCalled(RegisteredSubscription $subscription): void
    {
        if ($this->verbosity->isTracing($subscription->scope)) {
            $this->logger->forScope($subscription->scope)->recordTrace('Handler called', $this->describeSubscription($subscription));
        }
    }

    public function traceSkippedWhilePaused(RegisteredSubscription $subscription, ?object $event): void
    {
        if ($this->verbosity->isTracing($subscription->scope)) {
            $details = $event === null ? [] : $this->describeEvent($subscription, $event);
            $this->logger->forScope($subscription->scope)->recordTrace('Event skipped while paused', [...$this->describeSubscription($subscription), ...$details]);
        }
    }

    public function traceScheduleRun(ScheduleOrigin $origin, ScheduledRun $run): void
    {
        if ($this->verbosity->isTracing($origin->scope)) {
            $this->logger->forScope($origin->scope)->recordTrace('Schedule ran', $this->describeScheduledRun($origin, $run));
        }
    }

    public function traceScheduleRunSkippedWhilePaused(ScheduleOrigin $origin, ScheduledRun $run): void
    {
        if ($this->verbosity->isTracing($origin->scope)) {
            $this->logger->forScope($origin->scope)->recordTrace('Schedule run skipped while paused', $this->describeScheduledRun($origin, $run));
        }
    }

    /** @return array<string, string> */
    private function describeSubscription(RegisteredSubscription $subscription): array
    {
        return [
            'subscription' => $subscription->kind->value . ' ' . $subscription->describeMatch(),
            'id' => $subscription->id->value,
        ];
    }

    /** @return array<string, string|null> */
    private function describeEvent(RegisteredSubscription $subscription, object $event): array
    {
        return match (true) {
            $event instanceof StateChange => $this->describeStateChange($subscription, $event),
            $event instanceof HaEvent => ['event' => $event->type],
            $event instanceof TriggerEvent => ['platform' => $event->getPlatform()],
            $event instanceof TopicEvent => ['topic' => $event->topic, 'from' => $event->publisher?->value],
            $event instanceof MqttMessage => ['topic' => $event->topic],
            default => [],
        };
    }

    /** @return array<string, string> */
    private function describeStateChange(RegisteredSubscription $subscription, StateChange $change): array
    {
        $details = ['change' => \sprintf('%s → %s', $change->from->state ?? 'none', $change->to->state ?? 'none')];

        // An exact match already names the entity.
        if ($subscription->entityFilter !== null || $subscription->selector->findExactPattern() === null) {
            $details['entity'] = $change->entityId->value;
        }

        return $details;
    }

    /** @return array<string, string> */
    private function describeScheduledRun(ScheduleOrigin $origin, ScheduledRun $run): array
    {
        return ['schedule' => $origin->label(), 'scheduled_for' => (string) $run->scheduledFor];
    }
}
