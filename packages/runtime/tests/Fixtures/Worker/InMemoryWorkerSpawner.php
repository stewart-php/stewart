<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Worker;

use Amp\Cancellation;
use Amp\Future;
use Revolt\EventLoop;
use Stewart\Runtime\Broker\WorkerProcess;
use Stewart\Runtime\Broker\WorkerSpawner;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Stewart\Runtime\Kernel\SyntheticServices;
use Stewart\Runtime\Kernel\WorkerKernel;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Ipc\InMemoryTransports;
use Stewart\Runtime\Tests\Fixtures\Time\RecordingProcessTimeZone;
use Stewart\Runtime\Time\ProcessTimeZone;
use Stewart\Runtime\Worker\StoreBackendOpener;

use function Amp\async;

final readonly class InMemoryWorkerSpawner implements WorkerSpawner
{
    private const int KEEP_ALIVE_SECONDS = 3600;

    public function __construct(
        private IpcCodec $codecs,
        private ?StoreBackendOpener $storeBackends = null,
    ) {}

    public function spawn(WorkerId $workerId, Cancellation $deadline): WorkerProcess
    {
        $pair = InMemoryTransports::createPair($this->codecs);
        $kernel = new WorkerKernel($this->createServiceOverrides());

        /** @var Future<string> $session */
        $session = async(static function () use ($kernel, $pair): string {
            // A process pipe keeps the loop alive while the worker waits; in-memory queues do not.
            $keepAlive = EventLoop::repeat(self::KEEP_ALIVE_SECONDS, static function (): void {});

            try {
                return $kernel->run($pair->worker);
            } finally {
                EventLoop::cancel($keepAlive);
                $pair->worker->close();
            }
        });

        return new InMemoryWorkerProcess($pair->broker, $session);
    }

    private function createServiceOverrides(): SyntheticServices
    {
        $overrides = new SyntheticServices()->withService(ProcessTimeZone::class, new RecordingProcessTimeZone());

        return $this->storeBackends === null ? $overrides : $overrides->withService(StoreBackendOpener::class, $this->storeBackends);
    }
}
