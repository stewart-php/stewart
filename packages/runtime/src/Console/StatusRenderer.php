<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console;

use Stewart\Client\Component\ComponentProtocol;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Control\Protocol\Status\AppStatus;
use Stewart\Runtime\Control\Protocol\Status\ComponentStatus;
use Stewart\Runtime\Control\Protocol\Status\ConnectionState;
use Stewart\Runtime\Control\Protocol\Status\DeployStatus;
use Stewart\Runtime\Control\Protocol\Status\GeneratedCodeStatus;
use Stewart\Runtime\Control\Protocol\Status\MqttStatus;
use Stewart\Runtime\Control\Protocol\Status\RegistrationInfo;
use Stewart\Runtime\Control\Protocol\Status\ReloadStatus;
use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Control\Protocol\Status\ScheduleStatus;
use Stewart\Runtime\Control\Protocol\Status\WorkerStatus;
use Stewart\Runtime\Lifecycle\ComponentState;
use Stewart\Runtime\Model\HaCallOutcome;
use Stewart\Store\StoreHealth;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class StatusRenderer
{
    private const float LATENCY_QUANTILE = 0.95;

    private const int SHORT_COMMIT_LENGTH = 7;

    public function __construct(private StatusFormatter $formatter) {}

    public function render(OutputInterface $output, RuntimeSnapshot $snapshot, StatusView $view = StatusView::Overview): void
    {
        // The daemon's clock, so skew between a remote daemon and this CLI does not distort ages.
        $now = $snapshot->takenAt;

        $this->renderDaemonTable($output, $snapshot, $now);
        $this->renderWorkerTable($output, $snapshot, $now);
        $this->renderAppTable($output, $snapshot, $now);

        if ($view === StatusView::AppDetail) {
            $this->renderSubscriptionTable($output, $snapshot, $now);
            $this->renderScheduleTable($output, $snapshot, $now);
        }

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
            ['component', $this->describeComponent($snapshot->component, $now)],
            ['store', $this->describeStoreHealth($snapshot->store, $now)],
            ['mqtt', $this->describeMqtt($snapshot->mqtt, $now)],
            ['deploy', $this->describeDeploy($snapshot->deploy, $now)],
            ['reload', $this->describeReload($snapshot->reload, $now)],
            ['generated', $this->describeGeneratedCode($snapshot->generated, $now)],
            ['entities', (string) $daemon->entities],
            ['time zone', $daemon->timeZone],
            ['workers', \sprintf('%d live of %d', $broker->liveWorkers, $broker->workers)],
            ['HA calls', \sprintf('%d in flight, %d refused', $broker->inFlightHaCalls, $broker->refusedHaCalls)],
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
                $w->restarts === 0 ? '0' : \sprintf('%d (%d since start)', $w->restartsInWindow, $w->restarts),
                $w->outbox === null ? '-' : (string) $w->outbox->queued,
                $w->outbox === null ? '-' : (string) $w->outbox->dropped,
                $w->outbox === null ? '-' : \sprintf('%d (%d coalesced)', $w->outbox->stateBatchesSent, $w->outbox->coalescedStateChanges),
                (string) $w->inFlightHaCalls,
            ], $snapshot->workers),
        );
    }

    private function renderAppTable(OutputInterface $output, RuntimeSnapshot $snapshot, Instant $now): void
    {
        $this->renderTable(
            $output,
            'Apps',
            ['app', 'worker', 'state', 'reported', 'last handled', 'next run', 'subs', 'schedules', 'exposed', 'delivered', 'dropped', 'suppressed', 'runs', 'publishes', 'calls ok/failed', 'p95', 'failures'],
            array_map(fn(AppStatus $app): array => [
                $app->id,
                $app->workerId === null ? '-' : (string) $app->workerId,
                $this->formatAppState($app, $now),
                $this->formatter->formatTimeAgo($app->reportedAt, $now),
                $this->formatter->formatTimeAgo($app->lastHandledAt, $now),
                $this->formatter->formatNextRun($app->nextRunAt, $now),
                (string) $app->subscriptions,
                (string) $app->schedules,
                (string) $app->exposedEntities,
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

    private function renderSubscriptionTable(OutputInterface $output, RuntimeSnapshot $snapshot, Instant $now): void
    {
        $namesApps = \count($snapshot->apps) > 1;

        $this->renderTable(
            $output,
            'Subscriptions',
            [...($namesApps ? ['app'] : []), 'kind', 'match', 'received', 'handled', 'dropped', 'last handled'],
            array_map(fn(RegistrationInfo $subscription): array => [
                ...($namesApps ? [$subscription->appId] : []),
                $subscription->kind->value,
                $subscription->selector,
                (string) $subscription->received,
                (string) $subscription->handled,
                (string) $subscription->dropped,
                $this->formatter->formatTimeAgo($subscription->lastHandledAt, $now),
            ], $snapshot->subscriptions),
        );
    }

    private function renderScheduleTable(OutputInterface $output, RuntimeSnapshot $snapshot, Instant $now): void
    {
        $namesApps = \count($snapshot->apps) > 1;

        $this->renderTable(
            $output,
            'Schedules',
            [...($namesApps ? ['app'] : []), 'schedule', 'next run', 'last run'],
            array_map(fn(ScheduleStatus $schedule): array => [
                ...($namesApps ? [$schedule->appId] : []),
                $schedule->description,
                $this->formatter->formatNextRun($schedule->nextRunAt, $now),
                $this->formatter->formatTimeAgo($schedule->lastRunAt, $now),
            ], $snapshot->schedules),
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

    private function describeComponent(?ComponentStatus $component, Instant $now): string
    {
        if ($component === null) {
            return 'unknown';
        }

        $description = \sprintf('%s since %s', str_replace('_', ' ', $component->state->value), $this->formatter->formatTimeAgo($component->since, $now));

        if ($component->version !== null) {
            $description .= ', version ' . $component->version;
        }

        return match ($component->state) {
            ComponentState::Missing => $description . ', install the stewart integration in Home Assistant',
            ComponentState::ProtocolMismatch => \sprintf('%s, protocol %d where Stewart speaks %d', $description, $component->protocol ?? 0, ComponentProtocol::VERSION),
            ComponentState::Refused => $description . ', see the daemon log',
            ComponentState::Replaced => \sprintf('%s, another Stewart uses expose.instance "%s"', $description, $component->instance),
            ComponentState::Disabled => $description . ', expose.enabled is off',
            ComponentState::Unchecked, ComponentState::Active => $description,
        };
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

    private function describeMqtt(?MqttStatus $mqtt, Instant $now): string
    {
        if ($mqtt === null) {
            return 'not configured';
        }

        $description = $mqtt->phase->value;

        if ($mqtt->since !== null) {
            $description .= ' since ' . $this->formatter->formatTimeAgo($mqtt->since, $now);
        }

        if ($mqtt->lastError !== null) {
            $description .= ': ' . $mqtt->lastError;
        }

        if ($mqtt->reconnects > 0) {
            $description .= \sprintf(', %d %s', $mqtt->reconnects, $mqtt->reconnects === 1 ? 'reconnect' : 'reconnects');
        }

        if ($mqtt->lastOutage !== null) {
            $description .= ', last outage ' . $this->formatter->formatDuration($mqtt->lastOutage);
        }

        if ($mqtt->outboundQueued > 0) {
            $description .= \sprintf(', %d queued', $mqtt->outboundQueued);
        }

        $dropped = $mqtt->countDropped();

        return $dropped === 0 ? $description : \sprintf('%s, %d dropped', $description, $dropped);
    }

    private function describeDeploy(?DeployStatus $deploy, Instant $now): string
    {
        if ($deploy === null) {
            return 'not polling';
        }

        $description = \sprintf(
            '%s, polled %s',
            substr($deploy->commit, 0, self::SHORT_COMMIT_LENGTH),
            $this->formatter->formatTimeAgo($deploy->lastPolledAt, $now),
        );

        if ($deploy->lastFailure === null) {
            return $description;
        }

        return \sprintf(
            '%s, %d rejected, last %s %s: %s',
            $description,
            $deploy->failures,
            substr($deploy->lastFailure->commit, 0, self::SHORT_COMMIT_LENGTH),
            $this->formatter->formatTimeAgo($deploy->lastFailure->failedAt, $now),
            $deploy->lastFailure->reason,
        );
    }

    private function describeReload(?ReloadStatus $reload, Instant $now): string
    {
        if ($reload?->underway === true) {
            return 'underway';
        }

        $last = $reload?->last;

        if ($last === null) {
            return 'never';
        }

        $description = \sprintf('%s %s', $last->outcome->describeOutcome(), $this->formatter->formatTimeAgo($last->finishedAt, $now));

        if ($last->error !== null) {
            $description .= ': ' . $last->error;
        }

        if ($last->settingsNeedingRestart !== []) {
            $description .= '; restart to apply ' . implode(', ', $last->settingsNeedingRestart);
        }

        return $description;
    }

    private function describeGeneratedCode(?GeneratedCodeStatus $generated, Instant $now): string
    {
        if ($generated === null) {
            return 'not generated';
        }

        if (!$generated->hasCurrentFormat()) {
            return \sprintf('format %d, expected %d; run stewart generate', $generated->format, $generated->expectedFormat);
        }

        if ($generated->hasDrift()) {
            return \sprintf('%d added, %d removed; run stewart generate', \count($generated->added), \count($generated->removed));
        }

        return 'up to date, checked ' . $this->formatter->formatTimeAgo($generated->checkedAt, $now);
    }

    private function formatCalls(AppStatus $app): string
    {
        $succeeded = 0;
        $failed = 0;

        foreach ($app->haCalls as $stats) {
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
        foreach ($app->haCalls as $stats) {
            if ($stats->outcome !== HaCallOutcome::Succeeded || $stats->latency === null) {
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
