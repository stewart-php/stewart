<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Timers;
use Stewart\Runtime\Config\SupervisionConfig;
use Stewart\Runtime\Ipc\Message\Ping;
use Stewart\Runtime\Ipc\Message\Pong;
use Stewart\Runtime\Logging\EveryNthOccurrence;
use Stewart\Runtime\Time\RepeatingTimer;
use Throwable;

final class WorkerWatchdog
{
    private const int REMIND_EVERY_NTH_MISSED_PROBE = 6;

    private ?RepeatingTimer $timer = null;

    private readonly EveryNthOccurrence $unresponsiveReminders;

    public function __construct(
        private readonly WorkerSlotRegistry $slots,
        private readonly WorkerProbeSequence $probes,
        private readonly SupervisionConfig $supervision,
        private readonly Timers $timers,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
        $this->unresponsiveReminders = new EveryNthOccurrence(self::REMIND_EVERY_NTH_MISSED_PROBE);
    }

    public function startProbing(): void
    {
        $interval = $this->supervision->pingInterval->findDuration();

        if ($interval === null || $this->timer !== null) {
            return;
        }

        $this->timer = new RepeatingTimer(
            $this->timers,
            $interval,
            $this->probeWorkers(...),
            fn(Throwable $e) => $this->logger->warning('Watchdog probe failed', ['exception' => $e]),
        );
        $this->timer->start();
    }

    public function stopProbing(): void
    {
        $this->timer?->stop();
        $this->timer = null;
    }

    public function probeWorkers(): void
    {
        $nonce = $this->probes->advanceNonce();

        foreach ($this->slots->listLiveHandles() as $handle) {
            $missed = $handle->countMissedProbes($nonce);

            if ($this->supervision->killsUnresponsiveWorkers() && $missed >= $this->supervision->unresponsiveAfter) {
                $this->killUnresponsive($handle, $missed);

                continue;
            }

            if ($this->unresponsiveReminders->includesOccurrence($missed)) {
                $this->logger->warning('Worker is not answering liveness probes', [
                    'worker' => $handle->id->value,
                    'pid' => $handle->getPid(),
                    'apps' => $handle->getAppIds()->toStrings(),
                    'missed_probes' => $missed,
                ]);
            }

            $handle->send(new Ping($nonce, $this->clock->getNow()));
        }
    }

    public function recordPong(WorkerHandle $handle, Pong $pong): void
    {
        $handle->recordPong($pong, $this->clock->getNow());

        if ($pong->loopLag->isLongerThan($this->supervision->lagThreshold)) {
            $this->logger->warning('Worker event loop is lagging; is an app blocking?', [
                'worker' => $handle->id->value,
                'lag' => (string) $pong->loopLag,
                'apps' => $handle->getAppIds()->toStrings(),
            ]);
        }
    }

    public function countMissedProbes(WorkerHandle $handle): int
    {
        return $handle->countMissedProbes($this->probes->getCurrentNonce() + 1);
    }

    private function killUnresponsive(WorkerHandle $handle, int $missed): void
    {
        $this->logger->error('Worker stopped answering liveness probes; killing it', [
            'worker' => $handle->id->value,
            'pid' => $handle->getPid(),
            'apps' => $handle->getAppIds()->toStrings(),
            'missed_probes' => $missed,
        ]);

        $handle->terminate();
    }
}
