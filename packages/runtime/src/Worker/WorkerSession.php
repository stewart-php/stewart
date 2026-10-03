<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Psr\Log\LoggerInterface;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Message\WorkerReady;
use Stewart\Runtime\Ipc\Transport;
use Stewart\Runtime\Ipc\Wire\AppIdsFragment;
use Stewart\Runtime\Lifecycle\AppState;
use Stewart\Runtime\Model\WorkerId;
use Throwable;

use function Amp\async;

final readonly class WorkerSession
{
    public function __construct(
        private WorkerId $workerId,
        private Transport $transport,
        private AppLifecycle $apps,
        private StateCacheSync $stateCacheSync,
        private PendingCalls $pending,
        private BrokerMessageReader $reader,
        private WorkerShutdown $shutdown,
        private LoopErrorReporter $loopErrors,
        private LoggerInterface $logger,
    ) {}

    public function run(): string
    {
        $this->loopErrors->install();

        try {
            $this->shutdown->trackStartup(async($this->startApps(...)));

            async($this->reader->readUntilChannelCloses(...))->ignore();

            $this->shutdown->awaitStopped();

            $abandoned = $this->pending->failAll('the worker is shutting down');
        } finally {
            $this->loopErrors->restorePrevious();
        }

        return \sprintf('worker %d stopped (%s, %d calls abandoned)', $this->workerId->value, $this->shutdown->reason, $abandoned);
    }

    private function startApps(): void
    {
        try {
            $this->constructAndInitializeApps();
        } catch (Throwable $e) {
            $this->logger->error('Worker failed starting its apps', ['exception' => $e]);
            $this->shutdown->stopWithConfiguredGrace('startup failed');
        }
    }

    private function constructAndInitializeApps(): void
    {
        // Constructors may do I/O because the reader runs; initialize() waits for the state cache.
        $this->apps->constructApps();

        if (!$this->stateCacheSync->awaitFirstSeed($this->shutdown->startupCancellation())) {
            return;
        }

        $this->apps->initializeApps();

        if ($this->shutdown->isStopping()) {
            return;
        }

        try {
            $this->transport->send(new WorkerReady(
                appIds: AppIdsFragment::fromCollection($this->apps->listAppIdsInState(AppState::Running)),
                failedAppIds: AppIdsFragment::fromCollection($this->apps->listAppIdsInState(AppState::Failed)),
                memoryBytes: memory_get_usage(true),
            ));
        } catch (TransportException $e) {
            $this->logger->debug('Could not report ready; the broker channel is closed', ['exception' => $e]);
        }
    }
}
