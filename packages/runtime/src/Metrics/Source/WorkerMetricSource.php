<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Source;

use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Lifecycle\WorkerPhase;
use Stewart\Runtime\Metrics\Exposition\Collection\MetricFamilyCollection;
use Stewart\Runtime\Metrics\Exposition\MetricFamily;
use Stewart\Runtime\Metrics\Exposition\MetricLabels;
use Stewart\Runtime\Metrics\Exposition\PrometheusNumber;
use Stewart\Runtime\Metrics\RuntimeMetricSource;

final readonly class WorkerMetricSource implements RuntimeMetricSource
{
    public function collectMetrics(RuntimeSnapshot $snapshot): MetricFamilyCollection
    {
        $phases = MetricFamily::createGauge('stewart_worker_phase', 'Worker lifecycle phase; 1 for the current one.');
        $ready = MetricFamily::createGauge('stewart_worker_ready', 'Whether the worker finished booting its apps; 1 or 0.');
        $missedProbes = MetricFamily::createGauge('stewart_worker_missed_probes', 'Liveness probes the worker has not answered in a row.');
        $loopLag = MetricFamily::createGauge('stewart_worker_loop_lag_seconds', 'Event loop lag the worker last reported.');
        $memory = MetricFamily::createGauge('stewart_worker_memory_bytes', 'Memory the worker process last reported.');
        $lastPong = MetricFamily::createGauge('stewart_worker_last_pong_timestamp_seconds', 'Unix time of the last liveness answer.');
        $restartsInWindow = MetricFamily::createGauge('stewart_worker_restarts_in_window', 'Restarts inside the current restart budget window.');
        $restarts = MetricFamily::createCounter('stewart_worker_restarts_total', 'Restarts scheduled since the daemon started.');
        $quarantines = MetricFamily::createCounter('stewart_worker_quarantines_total', 'Times the worker was quarantined after using up its restart budget.');
        $restartDue = MetricFamily::createGauge('stewart_worker_restart_due_timestamp_seconds', 'Unix time of the next scheduled restart.');
        $inFlight = MetricFamily::createGauge('stewart_worker_service_calls_in_flight', 'Home Assistant calls of this worker awaiting an answer.');
        $queued = MetricFamily::createGauge('stewart_worker_outbox_queued', 'Messages waiting in the outbox to the worker.');
        $largestBatch = MetricFamily::createGauge('stewart_worker_outbox_largest_state_batch', 'Largest state change batch sent to the worker.');
        $dropped = MetricFamily::createCounter('stewart_worker_outbox_dropped_total', 'Messages dropped because the worker outbox was full.');
        $coalesced = MetricFamily::createCounter('stewart_worker_outbox_coalesced_state_changes_total', 'State changes merged into a newer one before delivery.');
        $batchesSent = MetricFamily::createCounter('stewart_worker_outbox_state_batches_sent_total', 'State change batches sent to the worker.');

        foreach ($snapshot->workers as $worker) {
            $labels = MetricLabels::withSingleLabel('worker', (string) $worker->workerId);

            foreach (WorkerPhase::cases() as $phase) {
                $phases = $phases->withSample($labels->withLabel('phase', $phase->value), $phase === $worker->phase);
            }

            $ready = $ready->withSample($labels, $worker->ready);
            $missedProbes = $missedProbes->withSample($labels, $worker->missedProbes);
            $restartsInWindow = $restartsInWindow->withSample($labels, $worker->restartsInWindow);
            $restarts = $restarts->withSample($labels, $worker->restarts);
            $quarantines = $quarantines->withSample($labels, $worker->quarantines);
            $inFlight = $inFlight->withSample($labels, $worker->inFlightServiceCalls);

            if ($worker->loopLag !== null) {
                $loopLag = $loopLag->withSample($labels, $worker->loopLag->toSeconds());
            }

            if ($worker->memoryBytes !== null) {
                $memory = $memory->withSample($labels, $worker->memoryBytes);
            }

            if ($worker->lastPongAt !== null) {
                $lastPong = $lastPong->withSample($labels, PrometheusNumber::convertToEpochSeconds($worker->lastPongAt));
            }

            if ($worker->restartDueAt !== null) {
                $restartDue = $restartDue->withSample($labels, PrometheusNumber::convertToEpochSeconds($worker->restartDueAt));
            }

            if ($worker->outbox !== null) {
                $queued = $queued->withSample($labels, $worker->outbox->queued);
                $largestBatch = $largestBatch->withSample($labels, $worker->outbox->largestStateBatch);
                $dropped = $dropped->withSample($labels, $worker->outbox->dropped);
                $coalesced = $coalesced->withSample($labels, $worker->outbox->coalescedStateChanges);
                $batchesSent = $batchesSent->withSample($labels, $worker->outbox->stateBatchesSent);
            }
        }

        return MetricFamilyCollection::fromFamilies([
            $phases, $ready, $missedProbes, $loopLag, $memory, $lastPong, $restartsInWindow, $restarts, $quarantines, $restartDue, $inFlight,
            $queued, $largestBatch, $dropped, $coalesced, $batchesSent,
        ]);
    }
}
