<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Source;

use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Lifecycle\AppState;
use Stewart\Runtime\Metrics\Exposition\Collection\MetricFamilyCollection;
use Stewart\Runtime\Metrics\Exposition\HistogramBuckets;
use Stewart\Runtime\Metrics\Exposition\MetricFamily;
use Stewart\Runtime\Metrics\Exposition\MetricLabels;
use Stewart\Runtime\Metrics\Exposition\PrometheusNumber;
use Stewart\Runtime\Metrics\RuntimeMetricSource;

final readonly class AppMetricSource implements RuntimeMetricSource
{
    public function collectMetrics(RuntimeSnapshot $snapshot): MetricFamilyCollection
    {
        $info = MetricFamily::createGauge('stewart_app_info', 'App class per app; always 1.');
        $states = MetricFamily::createGauge('stewart_app_state', 'App lifecycle state; 1 for the current one.');
        $paused = MetricFamily::createGauge('stewart_app_paused', 'Whether the app is paused; 1 or 0.');
        $subscriptions = MetricFamily::createGauge('stewart_app_subscriptions', 'Subscriptions the app holds.');
        $schedules = MetricFamily::createGauge('stewart_app_schedules', 'Schedules the app holds.');
        $delivered = MetricFamily::createCounter('stewart_app_events_delivered_total', 'Events delivered to the app handlers.');
        $subscriptionDropped = MetricFamily::createCounter('stewart_app_subscription_dropped_total', 'Events dropped because a subscription queue was full.');
        $scheduleRuns = MetricFamily::createCounter('stewart_app_schedule_runs_total', 'Scheduled handler runs.');
        $publishes = MetricFamily::createCounter('stewart_app_publishes_total', 'Messages the app published.');
        $failures = MetricFamily::createCounter('stewart_app_failures_total', 'Handler failures of the app.');
        $suppressed = MetricFamily::createCounter('stewart_app_suppressed_total', 'Work skipped while the app was paused.');
        $lastFailure = MetricFamily::createGauge('stewart_app_last_failure_timestamp_seconds', 'Unix time of the last app failure.');
        $serviceCalls = MetricFamily::createCounter('stewart_app_service_calls_total', 'Home Assistant calls of the app by outcome.');
        $serviceCallDuration = MetricFamily::createHistogram('stewart_app_service_call_duration_seconds', 'Home Assistant call latency of the app by outcome.');

        foreach ($snapshot->apps as $app) {
            $labels = MetricLabels::withSingleLabel('app', $app->id)->withLabel('worker', $app->workerId === null ? '' : (string) $app->workerId);
            $info = $info->withSample($labels->withLabel('class', $app->class), 1);

            foreach (AppState::cases() as $state) {
                $states = $states->withSample($labels->withLabel('state', $state->value), $state === $app->state);
            }

            $paused = $paused->withSample($labels, $app->pause !== null);
            $subscriptions = $subscriptions->withSample($labels, $app->subscriptions);
            $schedules = $schedules->withSample($labels, $app->schedules);
            $delivered = $delivered->withSample($labels, $app->counters->delivered);
            $subscriptionDropped = $subscriptionDropped->withSample($labels, $app->counters->subscriptionDropped);
            $scheduleRuns = $scheduleRuns->withSample($labels, $app->counters->scheduleRuns);
            $publishes = $publishes->withSample($labels, $app->counters->publishes);
            $failures = $failures->withSample($labels, $app->counters->failures);
            $suppressed = $suppressed->withSample($labels, $app->counters->suppressed);

            if ($app->lastFailure !== null) {
                $lastFailure = $lastFailure->withSample($labels, PrometheusNumber::convertToEpochSeconds($app->lastFailure->at));
            }

            foreach ($app->serviceCalls as $calls) {
                $outcomeLabels = $labels->withLabel('outcome', $calls->outcome->value);
                $serviceCalls = $serviceCalls->withSample($outcomeLabels, $calls->count);

                if ($calls->latency !== null) {
                    $serviceCallDuration = $serviceCallDuration->withHistogram($outcomeLabels, HistogramBuckets::fromLatencyHistogram($calls->latency));
                }
            }
        }

        return MetricFamilyCollection::fromFamilies([
            $info, $states, $paused, $subscriptions, $schedules, $delivered, $subscriptionDropped, $scheduleRuns, $publishes,
            $failures, $suppressed, $lastFailure, $serviceCalls, $serviceCallDuration,
        ]);
    }
}
