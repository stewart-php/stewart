<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Trigger\TriggerEvent;
use Stewart\Runtime\Broker\Exposure\ExposedEntityCommand;
use Stewart\Runtime\Broker\Exposure\ExposedEntitySync;
use Stewart\Runtime\Broker\Exposure\ExposureCommandRouter;
use Stewart\Runtime\Broker\Trigger\TriggerRejections;
use Stewart\Runtime\Ipc\Message\ExposedEntitySynced;
use Stewart\Runtime\Ipc\Message\HaConnectionLost;
use Stewart\Runtime\Ipc\Message\StateResynced;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Support\State\StateUpdate;
use Stewart\Support\Wire\TriggerSpec;
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
        private RegistryRefresher $registryRefresher,
        private ExposureCommandRouter $commandRouter,
        private GeneratedCodeDrift $drift,
    ) {}

    public function stateUpdated(StateUpdate $update): void
    {
        $this->router->broadcastStateUpdate($update);
    }

    public function eventFired(HaEvent $event): void
    {
        $this->registryRefresher->scheduleRefreshFor($event);
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

    public function exposedEntitySynced(ExposedEntitySync $sync): void
    {
        $this->slots->findHandleForWorker($sync->owner)?->send(new ExposedEntitySynced(ResourceScope::forApp($sync->appId), $sync->key, $sync->snapshot));
    }

    public function exposedEntityCommanded(ExposedEntityCommand $command): void
    {
        $this->commandRouter->routeCommand($command);
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
        $this->drift->recheckGeneratedCode();
    }

    public function connectionFailed(Throwable $error): void
    {
        $this->run->stopWithError('Home Assistant rejected the connection', $error);
    }
}
