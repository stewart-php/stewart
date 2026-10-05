<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Psr\Log\LoggerInterface;
use Stewart\Runtime\Broker\Message\WorkerMessageDispatcher;
use Stewart\Runtime\Ipc\Message\StateSnapshot;
use Stewart\Runtime\Ipc\Message\WorkerMessage;

final readonly class BrokerWorkerEvents implements WorkerPoolListener
{
    public function __construct(
        private HaSession $session,
        private BootstrapMessageFactory $bootstrapMessageFactory,
        private ConnectionTracker $connection,
        private WorkerMessageDispatcher $messages,
        private SubscriptionRegistry $registry,
        private BrokerRun $run,
        private LoggerInterface $logger,
    ) {}

    public function workerSpawned(WorkerHandle $handle): void
    {
        $snapshot = $this->session->snapshotStateCache();

        $handle->send($this->bootstrapMessageFactory->createBootstrapMessage($handle, $this->session->getTimeZone(), $this->session->getHaUserId()));
        $handle->send(new StateSnapshot($snapshot->states, $snapshot->revision));

        if ($this->connection->lastLoss !== null) {
            $handle->send($this->connection->lastLoss);
        }
    }

    public function workerMessage(WorkerHandle $handle, WorkerMessage $message): void
    {
        $this->messages->dispatch($handle, $message);
    }

    public function workerGone(WorkerHandle $handle, string $reason): void
    {
        $this->registry->removeWorkerSubscriptions($handle->id);

        if ($this->run->isRunning()) {
            $this->logger->error('Worker died', [
                'worker' => $handle->id->value,
                'pid' => $handle->getPid(),
                'apps' => $handle->getAppIds()->toStrings(),
                'reason' => $reason,
            ]);
        }
    }
}
