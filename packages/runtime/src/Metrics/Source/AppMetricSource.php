<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Source;

use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Lifecycle\AppState;
use Stewart\Runtime\Metrics\Exposition\Histogram;
use Stewart\Runtime\Metrics\Exposition\MetricFamily;
use Stewart\Runtime\Metrics\Exposition\MetricLabels;
use Stewart\Runtime\Metrics\Exposition\MetricType;
use Stewart\Runtime\Metrics\Exposition\PrometheusNumber;
use Stewart\Runtime\Metrics\RuntimeMetricSource;
use Stewart\Runtime\Model\ResourceScope;

final readonly class AppMetricSource implements RuntimeMetricSource
{
    /** @return list<MetricFamily> */
    public function collectMetrics(RuntimeSnapshot $snapshot): array
    {
        $appLabels = ['app', 'worker'];
        $outcomeLabels = [...$appLabels, 'outcome'];
        $info = new MetricFamily('stewart_app_info', 'App class per app; always 1.', MetricType::Gauge, [...$appLabels, 'class']);
        $states = new MetricFamily('stewart_app_state', 'App lifecycle state; 1 for the current one.', MetricType::Gauge, [...$appLabels, 'state']);
        $paused = new MetricFamily('stewart_app_paused', 'Whether the app is paused; 1 or 0.', MetricType::Gauge, $appLabels);
        $subscriptions = new MetricFamily('stewart_app_subscriptions', 'Subscriptions the app holds.', MetricType::Gauge, $appLabels);
        $schedules = new MetricFamily('stewart_app_schedules', 'Schedules the app holds.', MetricType::Gauge, $appLabels);
        $exposedEntities = new MetricFamily('stewart_app_exposed_entities', 'Home Assistant entities the app exposes, unavailable ones included.', MetricType::Gauge, $appLabels);
        $delivered = new MetricFamily('stewart_app_events_delivered_total', 'Events delivered to the app handlers.', MetricType::Counter, $appLabels);
        $subscriptionDropped = new MetricFamily('stewart_app_subscription_dropped_total', 'Events dropped because a subscription queue was full.', MetricType::Counter, $appLabels);
        $scheduleRuns = new MetricFamily('stewart_app_schedule_runs_total', 'Scheduled handler runs.', MetricType::Counter, $appLabels);
        $publishes = new MetricFamily('stewart_app_publishes_total', 'Messages the app published.', MetricType::Counter, $appLabels);
        $failures = new MetricFamily('stewart_app_failures_total', 'Handler failures of the app.', MetricType::Counter, $appLabels);
        $suppressed = new MetricFamily('stewart_app_suppressed_total', 'Work skipped while the app was paused.', MetricType::Counter, $appLabels);
        $lastFailure = new MetricFamily('stewart_app_last_failure_timestamp_seconds', 'Unix time of the last app failure.', MetricType::Gauge, $appLabels);
        $serviceCalls = new MetricFamily('stewart_app_service_calls_total', 'Home Assistant calls of the app by outcome.', MetricType::Counter, $outcomeLabels);
        $exposedCommands = new MetricFamily('stewart_app_exposed_commands_total', 'Home Assistant commands on the app\'s exposed entities by outcome.', MetricType::Counter, $outcomeLabels);
        $serviceCallDuration = new MetricFamily('stewart_app_service_call_duration_seconds', 'Home Assistant call latency of the app by outcome.', MetricType::Histogram, $outcomeLabels);

        foreach ($snapshot->apps as $app) {
            $labels = MetricLabels::fromLabel('app', $app->id)->withLabel('worker', $app->workerId === null ? '' : (string) $app->workerId);

            if (ResourceScope::tryFromWireValue($app->id)?->isShared() !== true) {
                $info->recordSample($labels->withLabel('class', $app->class), 1);

                foreach (AppState::cases() as $state) {
                    $states->recordSample($labels->withLabel('state', $state->value), $state === $app->state);
                }

                $paused->recordSample($labels, $app->pause !== null);
            }

            $subscriptions->recordSample($labels, $app->subscriptions);
            $schedules->recordSample($labels, $app->schedules);
            $exposedEntities->recordSample($labels, $app->exposedEntities);
            $delivered->recordSample($labels, $app->counters->delivered);
            $subscriptionDropped->recordSample($labels, $app->counters->subscriptionDropped);
            $scheduleRuns->recordSample($labels, $app->counters->scheduleRuns);
            $publishes->recordSample($labels, $app->counters->publishes);
            $failures->recordSample($labels, $app->counters->failures);
            $suppressed->recordSample($labels, $app->counters->suppressed);

            if ($app->lastFailure !== null) {
                $lastFailure->recordSample($labels, PrometheusNumber::convertToEpochSeconds($app->lastFailure->at));
            }

            foreach ($app->exposedCommands as $commands) {
                $exposedCommands->recordSample($labels->withLabel('outcome', $commands->outcome->value), $commands->count);
            }

            foreach ($app->serviceCalls as $calls) {
                $outcomeLabels = $labels->withLabel('outcome', $calls->outcome->value);
                $serviceCalls->recordSample($outcomeLabels, $calls->count);

                if ($calls->latency !== null) {
                    $serviceCallDuration->recordHistogram($outcomeLabels, Histogram::fromLatencyHistogram($calls->latency));
                }
            }
        }

        return [
            $info, $states, $paused, $subscriptions, $schedules, $exposedEntities, $delivered, $subscriptionDropped, $scheduleRuns, $publishes,
            $failures, $suppressed, $lastFailure, $serviceCalls, $serviceCallDuration, $exposedCommands,
        ];
    }
}
