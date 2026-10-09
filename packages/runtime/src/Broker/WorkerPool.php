<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Amp\Cancellation;
use Amp\CancelledException;
use Psr\Log\LoggerInterface;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\TimerHandle;
use Stewart\Contracts\Time\Timers;
use Stewart\Runtime\Config\SupervisionConfig;
use Stewart\Runtime\Exception\BrokerException;
use Stewart\Runtime\Ipc\Message\Shutdown;
use Stewart\Runtime\Ipc\Message\WorkerMessage;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Support\Time\Deadlines;
use Throwable;

use function Amp\async;
use function Amp\Future\awaitAll;

final class WorkerPool
{
    private const int JOIN_MARGIN_MILLISECONDS = 1_000;

    private const int SPAWN_TIMEOUT_SECONDS = 10;

    private const int EXIT_RESULT_WAIT_SECONDS = 2;

    private bool $running = true;

    public function __construct(
        private readonly WorkerSpawner $spawner,
        private readonly WorkerSlotRegistry $slots,
        private readonly WorkerRestartPolicy $restartPolicy,
        private readonly WorkerProbeSequence $probes,
        private readonly SupervisionConfig $supervision,
        private readonly WorkerMessageReader $messageReader,
        private readonly LoggerInterface $logger,
        private readonly OutboxLimits $outboxLimits,
        private readonly Timers&Deadlines $timers,
        private readonly Clock $clock,
    ) {}

    public function startWorker(WorkerSlot $slot, WorkerPoolListener $listener): bool
    {
        if (!$this->running) {
            return false;
        }

        $state = $this->slots->findOrCreateSlotState($slot, $listener);
        $state->beginSpawn();
        $process = $this->spawnProcessOrScheduleRestart($state, $slot);

        if ($process === null) {
            return false;
        }

        if (!$state->isSpawning()) {
            $process->close();

            return false;
        }

        $this->attachHandle($state, $slot, $process, $listener);

        return true;
    }

    public function markReady(WorkerHandle $handle): void
    {
        if ($handle->isReady() || $handle->isTerminated()) {
            return;
        }

        $handle->markReady();

        $state = $this->slots->findSlotState($handle->id);

        if ($state?->isLive($handle) === true) {
            $state->cancelReadyDeadline();
        }
    }

    public function shutdown(string $reason, Duration $grace): void
    {
        $this->running = false;
        $states = $this->slots->listSlotStates();

        foreach ($states as $state) {
            $state->stop();
        }

        $deadline = $this->timers->timeout($grace->plus(Duration::milliseconds(self::JOIN_MARGIN_MILLISECONDS)));
        $joins = [];

        foreach ($states as $state) {
            $handle = $state->handle;

            if ($handle === null || $handle->isTerminated()) {
                continue;
            }

            $handle->send(new Shutdown($reason, $grace));
            $joins[] = async(fn() => $this->awaitWorkerExit($handle, $deadline));
        }

        awaitAll($joins);
    }

    public function terminateWorkers(): void
    {
        foreach ($this->slots->listSlotStates() as $state) {
            $state->handle?->terminate();
        }
    }

    private function spawnProcessOrScheduleRestart(WorkerSlotState $state, WorkerSlot $slot): ?WorkerProcess
    {
        try {
            return $this->spawnProcess($slot->workerId);
        } catch (Throwable $e) {
            $this->logger->error('Could not start worker; its automations are not running', [
                'worker' => $slot->workerId->value,
                'apps' => $slot->listAppIds()->toStrings(),
                'exception' => $e,
            ]);

            // shutdown() may stop the slot while spawn() is suspended.
            if ($state->isSpawning()) {
                $state->markSpawnFailed();
                $this->scheduleRestart($state);
            }

            return null;
        }
    }

    /** @throws Throwable */
    private function spawnProcess(WorkerId $workerId): WorkerProcess
    {
        $timeout = Duration::seconds(self::SPAWN_TIMEOUT_SECONDS);

        try {
            return $this->spawner->spawn($workerId, $this->timers->timeout($timeout));
        } catch (CancelledException $e) {
            throw BrokerException::workerSpawnTimedOut($workerId, $timeout, $e);
        }
    }

    private function attachHandle(WorkerSlotState $state, WorkerSlot $slot, WorkerProcess $process, WorkerPoolListener $listener): void
    {
        $handle = new WorkerHandle($slot->workerId, $process, $slot, $this->logger, $this->outboxLimits, $this->probes->getCurrentNonce());
        $state->recordSpawnedHandle($handle, $this->armReadyDeadline($handle))?->terminate();

        $handle->relayOutput();
        $this->messageReader->readMessagesInBackground(
            $handle,
            static fn(WorkerMessage $message) => $listener->workerMessage($handle, $message),
            fn(string $reason) => $this->onWorkerGone($state, $handle, $reason),
        );
        $listener->workerSpawned($handle);

        $this->logger->debug('Worker started', ['worker' => $handle->id->value, 'pid' => $handle->getPid(), 'apps' => $slot->listAppIds()->toStrings()]);
    }

    private function awaitWorkerExit(WorkerHandle $handle, Cancellation $deadline): void
    {
        try {
            $summary = $handle->awaitExit($deadline);
            $this->logger->debug('Worker stopped cleanly', ['worker' => $handle->id->value, 'summary' => $summary]);
        } catch (Throwable $e) {
            if (!$handle->isTerminated()) {
                $this->logger->warning('Worker did not stop in time; killing it', [
                    'worker' => $handle->id->value,
                    'exception' => $e,
                ]);
            }
        } finally {
            $handle->terminate();
        }
    }

    private function armReadyDeadline(WorkerHandle $handle): ?TimerHandle
    {
        $readyTimeout = $this->supervision->readyTimeout->findDuration();

        if ($readyTimeout === null) {
            return null;
        }

        // A worker that answers pings but never reports ready is treated as dead.
        return $this->timers->startTimer($readyTimeout, function () use ($handle, $readyTimeout): void {
            if ($handle->isReady() || $handle->isTerminated()) {
                return;
            }

            $this->logger->error('Worker never reported its apps ready; restarting it', [
                'worker' => $handle->id->value,
                'pid' => $handle->getPid(),
                'apps' => $handle->getAppIds()->toStrings(),
                'ready_timeout' => (string) $readyTimeout,
            ]);

            $handle->terminate();
        });
    }

    private function onWorkerGone(WorkerSlotState $state, WorkerHandle $handle, string $reason): void
    {
        // Only the live handle counts: a displaced handle or a slot stopped by shutdown must not restart.
        if (!$state->isLive($handle)) {
            return;
        }

        if (!$handle->isTerminated()) {
            $this->logUnexpectedExit($handle);
        }

        // Shutdown may have stopped the slot while the exit result was awaited.
        if (!$state->isLive($handle)) {
            return;
        }

        $handle->terminate();
        $state->markGone();
        $state->listener->workerGone($handle, $reason);

        if ($this->running) {
            $this->scheduleRestart($state);
        }
    }

    private function logUnexpectedExit(WorkerHandle $handle): void
    {
        try {
            $summary = $handle->awaitExit($this->timers->timeout(Duration::seconds(self::EXIT_RESULT_WAIT_SECONDS)));
            $this->logger->warning('Worker exited', ['worker' => $handle->id->value, 'summary' => $summary]);
        } catch (CancelledException) {
            return;
        } catch (Throwable $e) {
            $this->logger->error('Worker exited with an error', ['worker' => $handle->id->value, 'exception' => $e]);
        }
    }

    private function scheduleRestart(WorkerSlotState $state): void
    {
        $slot = $state->slot;
        $decision = $this->restartPolicy->decideRestart($slot->workerId);

        if ($decision->delay === null) {
            $this->logger->error('Worker quarantined after repeated failures; its automations are not running', [
                'worker' => $slot->workerId->value,
                'apps' => $slot->listAppIds()->toStrings(),
                'attempts' => $this->supervision->restartAttempts,
                'window' => (string) $this->supervision->restartWindow,
            ]);

            $state->markQuarantined();
            $state->listener->workerQuarantined($slot);

            return;
        }

        $delay = $decision->delay;

        $this->logger->info('Restarting worker', ['worker' => $slot->workerId->value, 'apps' => $slot->listAppIds()->toStrings(), 'in' => (string) $delay]);

        $state->recordScheduledRestart(
            $this->timers->startTimer($delay, fn() => $this->restartAfterBackoff($state)),
            $this->clock->getNow()->plus($delay),
        );
    }

    private function restartAfterBackoff(WorkerSlotState $state): void
    {
        try {
            $this->startWorker($state->slot, $state->listener);
        } catch (Throwable $e) {
            $this->logger->error('Could not restart worker', ['worker' => $state->slot->workerId->value, 'exception' => $e]);
        }
    }
}
