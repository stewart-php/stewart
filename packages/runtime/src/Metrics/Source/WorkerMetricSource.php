<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Source;

use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Lifecycle\WorkerPhase;
use Stewart\Runtime\Metrics\Exposition\Collection\MetricFamilyCollection;
use Stewart\Runtime\Metrics\Exposition\MetricFamily;
use Stewart\Runtime\Metrics\Exposition\MetricLabels;
use Stewart\Runtime\Metrics\Exposition\MetricType;
use Stewart\Runtime\Metrics\Exposition\PrometheusNumber;
use Stewart\Runtime\Metrics\RuntimeMetricSource;

final readonly class WorkerMetricSource implements RuntimeMetricSource
{
    public function collectMetrics(RuntimeSnapshot $snapshot): MetricFamilyCollection
    {
        $phases = new MetricFamily('stewart_worker_phase', 'Worker lifecycle phase; 1 for the current one.', MetricType::Gauge);
        $ready = new MetricFamily('stewart_worker_ready', 'Whether the worker finished booting its apps; 1 or 0.', MetricType::Gauge);
        $missedProbes = new MetricFamily('stewart_worker_missed_probes', 'Liveness probes the worker has not answered in a row.', MetricType::Gauge);
        $loopLag = new MetricFamily('stewart_worker_loop_lag_seconds', 'Event loop lag the worker last reported.', MetricType::Gauge);
        $memory = new MetricFamily('stewart_worker_memory_bytes', 'Memory the worker process last reported.', MetricType::Gauge);
        $lastPong = new MetricFamily('stewart_worker_last_pong_timestamp_seconds', 'Unix time of the last liveness answer.', MetricType::Gauge);
        $restartsInWindow = new MetricFamily('stewart_worker_restarts_in_window', 'Restarts inside the current restart budget window.', MetricType::Gauge);
        $restarts = new MetricFamily('stewart_worker_restarts_total', 'Restarts scheduled since the daemon started.', MetricType::Counter);
        $quarantines = new MetricFamily('stewart_worker_quarantines_total', 'Times the worker was quarantined after using up its restart budget.', MetricType::Counter);
        $restartDue = new MetricFamily('stewart_worker_restart_due_timestamp_seconds', 'Unix time of the next scheduled restart.', MetricType::Gauge);
        $inFlight = new MetricFamily('stewart_worker_service_calls_in_flight', 'Home Assistant calls of this worker awaiting an answer.', MetricType::Gauge);
        $queued = new MetricFamily('stewart_worker_outbox_queued', 'Messages waiting in the outbox to the worker.', MetricType::Gauge);
        $largestBatch = new MetricFamily('stewart_worker_outbox_largest_state_batch', 'Largest state change batch sent to the worker.', MetricType::Gauge);
        $dropped = new MetricFamily('stewart_worker_outbox_dropped_total', 'Messages dropped because the worker outbox was full.', MetricType::Counter);
        $coalesced = new MetricFamily('stewart_worker_outbox_coalesced_state_changes_total', 'State changes merged into a newer one before delivery.', MetricType::Counter);
        $batchesSent = new MetricFamily('stewart_worker_outbox_state_batches_sent_total', 'State change batches sent to the worker.', MetricType::Counter);

        foreach ($snapshot->workers as $worker) {
            $labels = MetricLabels::fromLabel('worker', (string) $worker->workerId);

            foreach (WorkerPhase::cases() as $phase) {
                $phases->recordSample($labels->withLabel('phase', $phase->value), $phase === $worker->phase);
            }

            $ready->recordSample($labels, $worker->ready);
            $missedProbes->recordSample($labels, $worker->missedProbes);
            $restartsInWindow->recordSample($labels, $worker->restartsInWindow);
            $restarts->recordSample($labels, $worker->restarts);
            $quarantines->recordSample($labels, $worker->quarantines);
            $inFlight->recordSample($labels, $worker->inFlightServiceCalls);

            if ($worker->loopLag !== null) {
                $loopLag->recordSample($labels, $worker->loopLag->toSeconds());
            }

            if ($worker->memoryBytes !== null) {
                $memory->recordSample($labels, $worker->memoryBytes);
            }

            if ($worker->lastPongAt !== null) {
                $lastPong->recordSample($labels, PrometheusNumber::convertToEpochSeconds($worker->lastPongAt));
            }

            if ($worker->restartDueAt !== null) {
                $restartDue->recordSample($labels, PrometheusNumber::convertToEpochSeconds($worker->restartDueAt));
            }

            if ($worker->outbox !== null) {
                $queued->recordSample($labels, $worker->outbox->queued);
                $largestBatch->recordSample($labels, $worker->outbox->largestStateBatch);
                $dropped->recordSample($labels, $worker->outbox->dropped);
                $coalesced->recordSample($labels, $worker->outbox->coalescedStateChanges);
                $batchesSent->recordSample($labels, $worker->outbox->stateBatchesSent);
            }
        }

        return MetricFamilyCollection::fromFamilies([
            $phases, $ready, $missedProbes, $loopLag, $memory, $lastPong, $restartsInWindow, $restarts, $quarantines, $restartDue, $inFlight,
            $queued, $largestBatch, $dropped, $coalesced, $batchesSent,
        ]);
    }
}
