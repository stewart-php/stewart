<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Amp\Cancellation;
use Amp\Parallel\Context\ProcessContextFactory;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Stewart\Runtime\Model\WorkerId;

final readonly class ProcessWorkerSpawner implements WorkerSpawner
{
    private const string ENTRYPOINT = __DIR__ . '/../../bin/worker.php';

    public function __construct(
        private ProcessContextFactory $contexts,
        private IpcCodec $ipcCodec,
    ) {}

    public function spawn(WorkerId $workerId, Cancellation $deadline): WorkerProcess
    {
        return new ProcessContextWorker($this->contexts->start([self::ENTRYPOINT, (string) $workerId], $deadline), $this->ipcCodec);
    }
}
