<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Reload;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Psr\Log\LoggerInterface;
use Stewart\Contracts\Time\Clock;
use Stewart\Runtime\Broker\BrokerRun;
use Stewart\Runtime\Lifecycle\ReloadOutcome;
use Throwable;

use function Amp\async;

final class ReloadCoordinator
{
    private ?DeferredCancellation $accepting = null;

    public function __construct(
        private readonly ReloadCheck $check,
        private readonly ReloadPlanner $planner,
        private readonly ReloadApplier $applier,
        private readonly ReloadState $state,
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
        async(fn() => $this->reloadProject($cancellation))->ignore();

        return ReloadRequestOutcome::Started;
    }

    private function reloadProject(Cancellation $cancellation): void
    {
        try {
            $report = $this->check->runReloadCheck($cancellation);
            $failure = $report->findFirstError();

            if ($failure !== null) {
                $this->recordFailure($failure);

                return;
            }

            if (!$this->run->isRunning()) {
                $this->state->abandonReload();

                return;
            }

            $plan = $this->planner->planReload($report);
            $this->applier->applyReload($plan);
            $this->recordApplied($plan);
        } catch (CancelledException) {
            $this->state->abandonReload();
        } catch (Throwable $e) {
            $this->recordFailure($e->getMessage());
        }
    }

    private function recordApplied(ReloadPlan $plan): void
    {
        $settings = $plan->settingsNeedingRestart;

        if ($settings === []) {
            $this->logger->info('Reloaded: every worker restarted on the current code and configuration', ['apps' => $plan->catalog->enabled->count()]);
        } else {
            $this->logger->warning('Reloaded, except for settings that only a restart applies', ['apps' => $plan->catalog->enabled->count(), 'settings' => $settings]);
        }

        $this->state->recordResult(new ReloadResult($settings === [] ? ReloadOutcome::Applied : ReloadOutcome::PartlyApplied, $this->clock->getNow(), null, $settings));
    }

    private function recordFailure(string $reason): void
    {
        $this->logger->error('Reload failed; the workers keep running as they were', ['reason' => $reason]);
        $this->state->recordResult(new ReloadResult(ReloadOutcome::Failed, $this->clock->getNow(), $reason));
    }
}
