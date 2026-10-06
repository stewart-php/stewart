<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Psr\Log\LoggerInterface;
use Stewart\Runtime\Broker\Http\ProbeListener;
use Stewart\Runtime\Broker\Mqtt\MqttLink;
use Stewart\Runtime\Broker\Mqtt\MqttMessageRouter;
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
        private ProbeListener $probes,
        private MqttLink $mqtt,
        private MqttMessageRouter $mqttRouter,
        private AppPauseService $pauses,
        private LoggerInterface $logger,
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
            $this->pauses->restoreStoredOverrides();

            // A stop can land while the previous step suspends; it has already closed what would start here.
            if ($this->run->isRunning()) {
                $this->control->start();
                $this->probes->start();
                $this->mqtt->startInBackground($this->mqttRouter);
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
            $this->stopProbeListener();
            $this->stopControlPlane();
            $this->signals->removeAll();
            $this->loopErrors->restorePrevious();
        }
    }

    private function stopProbeListener(): void
    {
        try {
            $this->probes->stop();
        } catch (Throwable $e) {
            $this->logger->error('Could not stop the probe listener while shutting down', ['exception' => $e]);
        }
    }

    private function stopControlPlane(): void
    {
        try {
            $this->control->stop();
        } catch (Throwable $e) {
            $this->logger->error('Could not stop the control plane while shutting down', ['exception' => $e]);
        }
    }
}
