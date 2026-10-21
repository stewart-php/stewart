<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Reload;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Psr\Log\LoggerInterface;
use Stewart\Contracts\Time\Clock;
use Stewart\Runtime\Broker\BrokerRun;
use Stewart\Runtime\Broker\WorkerPool;
use Stewart\Runtime\Lifecycle\ReloadOutcome;
use Throwable;

use function Amp\async;

final class ReloadCoordinator
{
    private ?DeferredCancellation $accepting = null;

    public function __construct(
        private readonly ReloadCheck $check,
        private readonly ReloadState $state,
        private readonly WorkerPool $pool,
        private readonly BrokerRun $run,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {}

    public function startAccepting(): void
    {
        $this->accepting ??= new DeferredCancellation();
    }

    public function stopAccepting(): void
    {
        $this->accepting?->cancel();
        $this->accepting = null;
    }

    public function requestReload(ReloadTrigger $trigger): ReloadRequestOutcome
    {
        if ($this->accepting === null || !$this->run->isRunning()) {
            return ReloadRequestOutcome::NotAccepting;
        }

        if ($this->state->isUnderway()) {
            return ReloadRequestOutcome::AlreadyUnderway;
        }

        $this->state->beginReload();
        $this->logger->info('Reloading: checking the project first', ['trigger' => $trigger->value]);
        $cancellation = $this->accepting->getCancellation();
        async(fn() => $this->reloadWorkers($cancellation))->ignore();

        return ReloadRequestOutcome::Started;
    }

    private function reloadWorkers(Cancellation $cancellation): void
    {
        try {
            $failure = $this->check->findCheckFailure($cancellation);

            if ($failure !== null) {
                $this->recordFailure($failure);

                return;
            }

            if (!$this->run->isRunning()) {
                $this->state->abandonReload();

                return;
            }

            $this->pool->replaceEveryWorker();
            $this->state->recordResult(new ReloadResult(ReloadOutcome::Applied, $this->clock->getNow()));
            $this->logger->info('Reloaded: every worker restarted on the current code');
        } catch (CancelledException) {
            $this->state->abandonReload();
        } catch (Throwable $e) {
            $this->recordFailure($e->getMessage());
        }
    }

    private function recordFailure(string $reason): void
    {
        $this->logger->error('Reload failed; the workers keep running as they were', ['reason' => $reason]);
        $this->state->recordResult(new ReloadResult(ReloadOutcome::Failed, $this->clock->getNow(), $reason));
    }
}
