<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broker;

use Amp\Cancellation;
use LogicException;
use RuntimeException;
use Stewart\Runtime\Broker\WorkerProcess;
use Stewart\Runtime\Broker\WorkerSpawner;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Testing\Async\Latch;

final class FakeWorkerSpawner implements WorkerSpawner
{
    /** @var array<int, list<FakeWorkerProcess>> */
    public array $spawned = [];

    public int $attempts = 0;

    private int $failuresLeft = 0;

    public function __construct(
        private readonly ?Latch $joinLatch = null,
        private readonly ?Latch $spawnLatch = null,
    ) {}

    public function failNext(int $times): void
    {
        $this->failuresLeft = $times;
    }

    public function spawn(WorkerId $workerId, Cancellation $deadline): WorkerProcess
    {
        ++$this->attempts;

        if ($this->failuresLeft > 0) {
            --$this->failuresLeft;

            throw new RuntimeException('could not fork');
        }

        $this->spawnLatch?->waitUntilOpen($deadline);

        $process = new FakeWorkerProcess($this->joinLatch);
        $this->spawned[$workerId->value][] = $process;

        return $process;
    }

    public function countSpawnedProcesses(): int
    {
        return array_sum(array_map(\count(...), $this->spawned));
    }

    public function getLatestProcess(int $workerId): FakeWorkerProcess
    {
        $processes = $this->spawned[$workerId] ?? [];

        return $processes[\count($processes) - 1] ?? throw new LogicException(\sprintf('Worker %d was never spawned.', $workerId));
    }
}
