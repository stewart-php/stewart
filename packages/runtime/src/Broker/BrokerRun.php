<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Amp\DeferredFuture;
use Closure;
use LogicException;
use Psr\Log\LoggerInterface;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Lifecycle\BrokerRunPhase;
use Throwable;

final class BrokerRun
{
    private BrokerRunPhase $phase = BrokerRunPhase::Idle;

    /** @var DeferredFuture<null> */
    private DeferredFuture $runFinished;

    public function __construct(
        private readonly WorkerPool $pool,
        private readonly WorkerWatchdog $watchdog,
        private readonly HaSession $session,
        private readonly ControlPlane $control,
        private readonly LoggerInterface $logger,
        private readonly Duration $brokerShutdownGrace,
        private readonly DaemonStartTime $startTime,
    ) {
        $this->runFinished = new DeferredFuture();
    }

    public function start(): void
    {
        $this->moveTo(BrokerRunPhase::Running);
        $this->runFinished = new DeferredFuture();
        $this->startTime->recordStart();
    }

    public function isRunning(): bool
    {
        return $this->phase === BrokerRunPhase::Running;
    }

    public function isStopping(): bool
    {
        return $this->phase === BrokerRunPhase::Stopping;
    }

    public function awaitStopped(): void
    {
        $this->runFinished->getFuture()->await();
    }

    public function stop(string $reason): void
    {
        $this->stopRun($reason, null);
    }

    public function stopWithError(string $reason, Throwable $error): void
    {
        $this->stopRun($reason, $error);
    }

    public function killWorkersNow(): void
    {
        if (!$this->isStopping()) {
            return;
        }

        $this->logger->warning('Killing workers without waiting for their grace');
        $this->pool->terminateWorkers();
    }

    private function stopRun(string $reason, ?Throwable $error): void
    {
        if (!$this->isRunning()) {
            return;
        }

        $this->moveTo(BrokerRunPhase::Stopping);

        $this->logger->info('Shutting down', ['reason' => $reason, 'grace' => (string) $this->brokerShutdownGrace]);

        $stepFailure = $this->runStopSteps($reason);
        $failure = $error ?? $stepFailure;
        $this->moveTo(BrokerRunPhase::Stopped);

        if ($failure === null) {
            $this->runFinished->complete();
        } else {
            $this->runFinished->error($failure);
        }
    }

    private function runStopSteps(string $reason): ?Throwable
    {
        $workersFailure = $this->attemptStopStep('Could not stop the workers while shutting down', function () use ($reason): void {
            $this->watchdog->stopProbing();
            $this->pool->shutdown($reason, $this->brokerShutdownGrace);
        });
        $sessionFailure = $this->attemptStopStep('Could not close the Home Assistant session while shutting down', $this->session->close(...));
        $controlFailure = $this->attemptStopStep('Could not stop the control plane while shutting down', $this->control->stop(...));

        return $workersFailure ?? $sessionFailure ?? $controlFailure;
    }

    private function moveTo(BrokerRunPhase $next): void
    {
        if (!$this->phase->canEnter($next)) {
            throw new LogicException(\sprintf('The broker run cannot move from %s to %s.', $this->phase->name, $next->name));
        }

        $this->phase = $next;
    }

    /** @param Closure(): void $step */
    private function attemptStopStep(string $failureMessage, Closure $step): ?Throwable
    {
        try {
            $step();
        } catch (Throwable $e) {
            $this->logger->error($failureMessage, ['exception' => $e]);

            return $e;
        }

        return null;
    }
}
