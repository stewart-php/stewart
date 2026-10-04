<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Future;
use Psr\Log\LoggerInterface;
use Stewart\Contracts\Time\Duration;
use Stewart\Support\Time\Deadlines;
use Throwable;

use function Amp\async;

final class WorkerShutdown
{
    public private(set) ?string $reason = null;

    /** @var DeferredFuture<null> */
    private readonly DeferredFuture $stopped;

    private readonly DeferredCancellation $halt;

    /** @var Future<mixed>|null */
    private ?Future $startup = null;

    public function __construct(
        private readonly AppLifecycle $apps,
        private readonly AppResources $resources,
        private readonly PendingCalls $pending,
        private readonly PendingHistoryQueries $pendingHistory,
        private readonly Deadlines $deadlines,
        private readonly LoggerInterface $logger,
        private readonly Duration $workerShutdownGrace,
    ) {
        $this->stopped = new DeferredFuture();
        $this->halt = new DeferredCancellation();
    }

    /** @param Future<mixed> $startup */
    public function trackStartup(Future $startup): void
    {
        $this->startup = $startup;
    }

    public function startupCancellation(): Cancellation
    {
        return $this->halt->getCancellation();
    }

    public function isStopping(): bool
    {
        return $this->reason !== null;
    }

    public function awaitStopped(): void
    {
        $this->stopped->getFuture()->await();
    }

    public function stopWithConfiguredGrace(string $reason): void
    {
        $this->stop($reason, $this->workerShutdownGrace);
    }

    public function stopAfterBrokerLoss(string $reason): void
    {
        $this->pending->failAll('the broker is gone');
        $this->pendingHistory->failAll('the broker is gone');
        $this->stopWithConfiguredGrace($reason);
    }

    public function stop(string $reason, Duration $grace): void
    {
        if ($this->reason !== null) {
            return;
        }

        $this->reason = $reason;
        $this->apps->markStopping();
        $this->halt->cancel();
        $deadline = $this->deadlines->timeout($grace);

        async(function () use ($deadline, $grace): void {
            $inTime = $this->stopAppsBefore($deadline);
            $abandoned = $this->apps->abandonUnfinishedApps();
            $this->resources->releaseAll();

            if (!$inTime) {
                $this->logger->warning('Apps did not stop within the grace period', ['grace' => (string) $grace, 'apps' => $abandoned->toStrings()]);
            }

            $this->stopped->complete();
        })->ignore();
    }

    private function stopAppsBefore(Cancellation $deadline): bool
    {
        try {
            $this->apps->disposeAppsBefore($deadline);
            // Apps still in initialize() dispose themselves when it returns.
            $this->startup?->await($deadline);
        } catch (CancelledException) {
            return false;
        } catch (Throwable $e) {
            $this->logger->error('Worker failed while stopping its apps', ['exception' => $e]);
        }

        return true;
    }
}
