<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console;

use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Control\Protocol\Status\AppStatus;
use Stewart\Runtime\Control\Protocol\Status\ConnectionState;
use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Control\Protocol\Status\WorkerStatus;
use Stewart\Runtime\Model\ServiceCallOutcome;
use Stewart\Store\StoreHealth;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class StatusRenderer
{
    private const float LATENCY_QUANTILE = 0.95;

    public function __construct(private StatusFormatter $formatter) {}

    public function render(OutputInterface $output, RuntimeSnapshot $snapshot): void
    {
        // The daemon's clock, so skew between a remote daemon and this CLI does not distort ages.
        $now = $snapshot->takenAt;

        $this->renderDaemonTable($output, $snapshot, $now);
        $this->renderWorkerTable($output, $snapshot, $now);
        $this->renderAppTable($output, $snapshot, $now);
        $this->renderFailureTable($output, $snapshot, $now);
    }

    private function renderDaemonTable(OutputInterface $output, RuntimeSnapshot $snapshot, Instant $now): void
    {
        $daemon = $snapshot->daemon;
        $connection = $snapshot->connection;
        $broker = $snapshot->broker;

        $this->renderTable($output, 'Daemon', ['', ''], [
            ['version', $daemon->version],
            ['pid', (string) $daemon->pid],
            ['up', $this->formatter->formatDuration($now->elapsedSince($daemon->startedAt))],
            ['memory', $this->formatter->formatBytes($daemon->memoryBytes)],
            ['home assistant', $this->describeConnection($snapshot, $now)],
            ['reconnects', $this->describeReconnects($connection)],
            ['store', $this->describeStoreHealth($snapshot->store, $now)],
            ['entities', (string) $daemon->entities],
            ['time zone', $daemon->timeZone],
            ['workers', \sprintf('%d live of %d', $broker->liveWorkers, $broker->workers)],
            ['service calls', \sprintf('%d in flight, %d refused', $broker->inFlightServiceCalls, $broker->refusedServiceCalls)],
            ['routing', \sprintf('%d subscriptions, %d patterns, cache %d hits / %d misses', $broker->routing->subscriptions, $broker->routing->patterns, $broker->routing->cacheHits, $broker->routing->cacheMisses)],
        ]);
    }

    private function renderWorkerTable(OutputInterface $output, RuntimeSnapshot $snapshot, Instant $now): void
    {
        $this->renderTable(
            $output,
            'Workers',
            ['worker', 'phase', 'pid', 'ready', 'apps', 'lag', 'memory', 'pong', 'missed', 'restarts', 'queued', 'dropped', 'batches', 'calls'],
            array_map(fn(WorkerStatus $w): array => [
                (string) $w->workerId,
                $w->phase->value . ($w->restartDueAt === null ? '' : ' ' . $this->formatter->formatTimeUntil($w->restartDueAt, $now)),
                $w->pid === null ? '-' : (string) $w->pid,
                $this->formatter->formatYesNo($w->ready),
                implode(', ', $w->appIds),
                $this->formatter->formatElapsed($w->loopLag),
                $this->formatter->formatBytes($w->memoryBytes),
                $this->formatter->formatTimeAgo($w->lastPongAt, $now),
                (string) $w->missedProbes,
                $w->restarts === 0 ? '0' : \sprintf('%d (%d total)', $w->restartsInWindow, $w->restarts),
                $w->outbox === null ? '-' : (string) $w->outbox->queued,
                $w->outbox === null ? '-' : (string) $w->outbox->dropped,
                $w->outbox === null ? '-' : \sprintf('%d (%d coalesced)', $w->outbox->stateBatchesSent, $w->outbox->coalescedStateChanges),
                (string) $w->inFlightServiceCalls,
            ], $snapshot->workers),
        );
    }

    private function renderAppTable(OutputInterface $output, RuntimeSnapshot $snapshot, Instant $now): void
    {
        $this->renderTable(
            $output,
            'Apps',
            ['app', 'worker', 'state', 'reported', 'subs', 'schedules', 'delivered', 'dropped', 'suppressed', 'runs', 'publishes', 'calls ok/failed', 'p95', 'failures'],
            array_map(fn(AppStatus $app): array => [
                $app->id,
                $app->workerId === null ? '-' : (string) $app->workerId,
                $this->formatAppState($app, $now),
                $this->formatter->formatTimeAgo($app->reportedAt, $now),
                (string) $app->subscriptions,
                (string) $app->schedules,
                (string) $app->counters->delivered,
                (string) $app->counters->subscriptionDropped,
                (string) $app->counters->suppressed,
                (string) $app->counters->scheduleRuns,
                (string) $app->counters->publishes,
                $this->formatCalls($app),
                $this->formatLatency($app),
                (string) $app->counters->failures,
            ], $snapshot->apps),
        );
    }

    private function formatAppState(AppStatus $app, Instant $now): string
    {
        $state = $app->state === null ? 'unknown' : $app->state->value;

        if ($app->pause !== null) {
            return \sprintf('%s (paused by %s %s)', $state, $app->pause->source->value, $this->formatter->formatTimeAgo($app->pause->since, $now));
        }

        if ($app->configPauseOverride !== null) {
            return \sprintf('%s (resumed by %s %s over config)', $state, $app->configPauseOverride->source->value, $this->formatter->formatTimeAgo($app->configPauseOverride->since, $now));
        }

        return $state;
    }

    private function renderFailureTable(OutputInterface $output, RuntimeSnapshot $snapshot, Instant $now): void
    {
        $rows = [];

        foreach ($snapshot->apps as $app) {
            $failure = $app->lastFailure;

            if ($failure !== null) {
                $rows[] = [$app->id, $this->formatter->formatTimeAgo($failure->at, $now), $failure->phase->value, $failure->reason ?? '-', $failure->message];
            }
        }

        $this->renderTable($output, 'Failures', ['app', 'when', 'phase', 'reason', 'message'], $rows);
    }

    private function describeConnection(RuntimeSnapshot $snapshot, Instant $now): string
    {
        $connection = $snapshot->connection;
        $description = $connection->phase->value;

        if ($connection->since !== null) {
            $description .= ' since ' . $this->formatter->formatTimeAgo($connection->since, $now);
        }

        if ($snapshot->daemon->haVersion !== null) {
            $description .= ', version ' . $snapshot->daemon->haVersion;
        }

        return $description;
    }

    private function describeReconnects(ConnectionState $connection): string
    {
        if ($connection->lastOutage === null) {
            return (string) $connection->reconnects;
        }

        return \sprintf('%d, last outage %s', $connection->reconnects, $this->formatter->formatDuration($connection->lastOutage));
    }

    private function describeStoreHealth(?StoreHealth $health, Instant $now): string
    {
        if ($health === null) {
            return 'not configured';
        }

        $state = $health->available ? 'up' : 'down';

        if ($health->lastFailure === null) {
            return $state;
        }

        return $health->lastFailureAt === null
            ? \sprintf('%s, last failure: %s', $state, $health->lastFailure)
            : \sprintf('%s, last failure %s: %s', $state, $this->formatter->formatTimeAgo($health->lastFailureAt, $now), $health->lastFailure);
    }

    private function formatCalls(AppStatus $app): string
    {
        $succeeded = 0;
        $failed = 0;

        foreach ($app->serviceCalls as $stats) {
            if ($stats->outcome->isFailure()) {
                $failed += $stats->count;
            } else {
                $succeeded += $stats->count;
            }
        }

        return \sprintf('%d/%d', $succeeded, $failed);
    }

    private function formatLatency(AppStatus $app): string
    {
        foreach ($app->serviceCalls as $stats) {
            if ($stats->outcome !== ServiceCallOutcome::Succeeded || $stats->latency === null) {
                continue;
            }

            $bound = $stats->latency->quantileBoundMs(self::LATENCY_QUANTILE);
            $last = max(0, ...$stats->latency->boundsMs);

            return $bound === null ? \sprintf('> %d ms', $last) : \sprintf('≤ %d ms', $bound);
        }

        return '-';
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    private function renderTable(OutputInterface $output, string $title, array $headers, array $rows): void
    {
        $output->writeln('<info>' . $title . '</info>');

        if ($rows === []) {
            $output->writeln('  (none)');
            $output->writeln('');

            return;
        }

        new Table($output)
            ->setStyle('compact')
            ->setHeaders($headers)
            ->setRows($rows)
            ->render();
        $output->writeln('');
    }
}
