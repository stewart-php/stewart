<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Source;

use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Lifecycle\WorkerPhase;
use Stewart\Runtime\Metrics\Exposition\MetricFamily;
use Stewart\Runtime\Metrics\Exposition\MetricLabels;
use Stewart\Runtime\Metrics\Exposition\MetricType;
use Stewart\Runtime\Metrics\Exposition\PrometheusNumber;
use Stewart\Runtime\Metrics\RuntimeMetricSource;

final readonly class WorkerMetricSource implements RuntimeMetricSource
{
    /** @return list<MetricFamily> */
    public function collectMetrics(RuntimeSnapshot $snapshot): array
    {
        $workerLabels = ['worker'];
        $phases = new MetricFamily('stewart_worker_phase', 'Worker lifecycle phase; 1 for the current one.', MetricType::Gauge, [...$workerLabels, 'phase']);
        $ready = new MetricFamily('stewart_worker_ready', 'Whether the worker finished booting its apps; 1 or 0.', MetricType::Gauge, $workerLabels);
        $missedProbes = new MetricFamily('stewart_worker_missed_probes', 'Liveness probes the worker has not answered in a row.', MetricType::Gauge, $workerLabels);
        $loopLag = new MetricFamily('stewart_worker_loop_lag_seconds', 'Event loop lag the worker last reported.', MetricType::Gauge, $workerLabels);
        $memory = new MetricFamily('stewart_worker_memory_bytes', 'Resident memory (RSS) the worker process last reported.', MetricType::Gauge, $workerLabels);
        $lastPong = new MetricFamily('stewart_worker_last_pong_timestamp_seconds', 'Unix time of the last liveness answer.', MetricType::Gauge, $workerLabels);
        $restartsInWindow = new MetricFamily('stewart_worker_restarts_in_window', 'Restarts inside the current restart budget window.', MetricType::Gauge, $workerLabels);
        $restarts = new MetricFamily('stewart_worker_restarts_total', 'Restarts scheduled since the daemon started.', MetricType::Counter, $workerLabels);
        $quarantines = new MetricFamily('stewart_worker_quarantines_total', 'Times the worker was quarantined after using up its restart budget.', MetricType::Counter, $workerLabels);
        $restartDue = new MetricFamily('stewart_worker_restart_due_timestamp_seconds', 'Unix time of the next scheduled restart.', MetricType::Gauge, $workerLabels);
        $inFlight = new MetricFamily('stewart_worker_service_calls_in_flight', 'Home Assistant calls of this worker awaiting an answer.', MetricType::Gauge, $workerLabels);
        $queued = new MetricFamily('stewart_worker_outbox_queued', 'Messages waiting in the outbox to the worker.', MetricType::Gauge, $workerLabels);
        $largestBatch = new MetricFamily('stewart_worker_outbox_largest_state_batch', 'Largest state change batch sent to the worker.', MetricType::Gauge, $workerLabels);
        $dropped = new MetricFamily('stewart_worker_outbox_dropped_total', 'Messages dropped because the worker outbox was full.', MetricType::Counter, $workerLabels);
        $coalesced = new MetricFamily('stewart_worker_outbox_coalesced_state_changes_total', 'State changes merged into a newer one before delivery.', MetricType::Counter, $workerLabels);
        $batchesSent = new MetricFamily('stewart_worker_outbox_state_batches_sent_total', 'State change batches sent to the worker.', MetricType::Counter, $workerLabels);

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

        return [
            $phases, $ready, $missedProbes, $loopLag, $memory, $lastPong, $restartsInWindow, $restarts, $quarantines, $restartDue, $inFlight,
            $queued, $largestBatch, $dropped, $coalesced, $batchesSent,
        ];
    }
}
