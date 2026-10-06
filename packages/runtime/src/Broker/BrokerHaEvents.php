<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Trigger\TriggerEvent;
use Stewart\Contracts\Trigger\TriggerSpec;
use Stewart\Runtime\Broker\Trigger\TriggerRejections;
use Stewart\Runtime\Ipc\Message\HaConnectionLost;
use Stewart\Runtime\Ipc\Message\StateResynced;
use Throwable;

final readonly class BrokerHaEvents implements HaSessionListener
{
    public function __construct(
        private HaSession $session,
        private EventRouter $router,
        private WorkerSlotRegistry $slots,
        private ConnectionTracker $connection,
        private BrokerRun $run,
        private TriggerRejections $triggerRejections,
    ) {}

    public function stateChanged(StateChange $change): void
    {
        $this->router->broadcastStateChange($change);
    }

    public function eventFired(HaEvent $event): void
    {
        $this->router->routeEvent($event);
    }

    public function triggerFired(TriggerSpec $spec, TriggerEvent $event): void
    {
        $this->router->routeTrigger($spec, $event);
    }

    public function triggerRejected(TriggerSpec $spec, string $reason): void
    {
        $this->triggerRejections->refuseSubscribers($spec, $reason);
    }

    public function connectionLost(string $reason, Instant $lostAt): void
    {
        $lost = new HaConnectionLost($lostAt, $reason);
        $this->connection->markReconnecting($lost);
        $this->slots->broadcast($lost);
    }

    public function reconnected(Duration $outage): void
    {
        $snapshot = $this->session->snapshotStateCache();
        $this->connection->markRestored($outage);

        // The registry goes first so a resynced change is matched against the current areas and labels.
        $this->slots->broadcast($this->session->snapshotRegistry()->toRegistrySnapshot());
        $this->slots->broadcast(new StateResynced($snapshot->states, $snapshot->revision, $outage));
    }

    public function connectionFailed(Throwable $error): void
    {
        $this->run->stopWithError('Home Assistant rejected the connection', $error);
    }
}
