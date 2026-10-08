<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Deploy;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Psr\Log\LoggerInterface;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Timers;
use Stewart\Runtime\Broker\BrokerRun;
use Stewart\Runtime\Config\GitDeployConfig;
use Stewart\Runtime\Exception\DeployException;
use Stewart\Runtime\Time\RepeatingTimer;
use Throwable;

use function Amp\async;

final class ReleasePoller
{
    private ?RepeatingTimer $timer = null;

    private ?DeferredCancellation $stop = null;

    private bool $polling = false;

    public function __construct(
        private readonly GitDeployConfig $gitDeploy,
        private readonly RunningReleaseDetector $runningRelease,
        private readonly ReleaseScript $script,
        private readonly DeployState $state,
        private readonly BrokerRun $run,
        private readonly Timers $timers,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {}

    public function startPolling(): void
    {
        $interval = $this->gitDeploy->poll->findDuration();

        if ($interval === null || $this->timer !== null) {
            return;
        }

        $commit = $this->runningRelease->findRunningCommit();

        if ($commit === null) {
            $this->logger->warning('deploy.git.poll is set, but Stewart is not running from a release directory, so new commits are not deployed.');

            return;
        }

        $this->state->recordRunningCommit($commit);
        $this->stop = new DeferredCancellation();
        $this->timer = new RepeatingTimer(
            $this->timers,
            $interval,
            $this->pollForRelease(...),
            fn(Throwable $e) => $this->logger->warning('Polling for a new commit failed', ['exception' => $e]),
        );
        $this->timer->start();
    }

    public function stopPolling(): void
    {
        $this->timer?->stop();
        $this->timer = null;
        $this->stop?->cancel();
        $this->stop = null;
    }

    public function pollForRelease(): void
    {
        if ($this->polling || $this->stop === null) {
            return;
        }

        $this->polling = true;
        $cancellation = $this->stop->getCancellation();

        async(function () use ($cancellation): void {
            try {
                $this->deployLatestRelease($cancellation);
            } catch (CancelledException) {
            } catch (Throwable $e) {
                $this->logger->warning('Could not prepare the latest commit; trying again at the next poll', ['exception' => $e]);
            } finally {
                $this->polling = false;
            }
        })->ignore();
    }

    /**
     * @throws DeployException
     * @throws CancelledException
     */
    private function deployLatestRelease(Cancellation $cancellation): void
    {
        $release = $this->script->prepareLatestRelease($cancellation);
        $this->state->recordPoll($this->clock->getNow());

        if ($release->state !== ReleaseState::Prepared) {
            return;
        }

        $failure = $this->script->findCheckFailure($release->commit, $cancellation);

        if ($failure !== null) {
            $this->state->recordFailure(new ReleaseFailure($release->commit, $failure, $this->clock->getNow()));
            $this->logger->error('A new commit failed stewart check; the running release stays', ['commit' => $release->commit->value, 'reason' => $failure]);

            return;
        }

        if (!$this->run->isRunning()) {
            return;
        }

        $this->script->stageRelease($release->commit, $cancellation);
        $this->logger->info('Deploying a new commit', ['commit' => $release->commit->value]);
        $this->run->stopToRestart('deploying commit ' . $release->commit->value);
    }
}
