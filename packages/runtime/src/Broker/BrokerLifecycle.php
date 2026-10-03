<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Runtime\Time\ProcessTimeZone;
use Stewart\Store\StoreBackend;
use Throwable;

final readonly class BrokerLifecycle
{
    public function __construct(
        private HaSession $session,
        private BrokerHaEvents $haEvents,
        private BrokerRun $run,
        private SignalHandlers $signals,
        private LoopErrorLogger $loopErrors,
        private WorkerStartup $workers,
        private GeneratedCodeDrift $drift,
        private ConnectionTracker $connection,
        private ProcessTimeZone $processTimeZone,
        private ControlPlane $control,
        private ?StoreBackend $store = null,
    ) {}

    /** @throws Throwable */
    public function run(): void
    {
        $this->loopErrors->install();
        $this->run->start();
        $this->signals->install();

        try {
            // Before Home Assistant: an unreachable store is a config error, not an outage.
            $this->store?->probe();

            // A stop can land while the previous step suspends; it has already closed what would start here.
            if ($this->run->isRunning()) {
                $this->control->start();
            }

            if ($this->run->isRunning()) {
                $this->session->open($this->haEvents);
            }

            if ($this->run->isRunning()) {
                $this->processTimeZone->useAsProcessDefault($this->session->getTimeZone());
                $this->connection->markConnected();
                $this->drift->warnIfGeneratedCodeDrifted();
                $this->workers->startWorkers();
            }

            $this->run->awaitStopped();
        } catch (Throwable $e) {
            $this->run->stop('fatal error');

            throw $e;
        } finally {
            $this->signals->removeAll();
            $this->loopErrors->restorePrevious();
        }
    }
}
