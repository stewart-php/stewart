<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broker;

use RuntimeException;
use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Trigger\TriggerEvent;
use Stewart\Contracts\Trigger\TriggerSpec;
use Stewart\Runtime\Broker\Exposure\ExposedEntityCommand;
use Stewart\Runtime\Broker\Exposure\ExposedEntitySync;
use Stewart\Runtime\Broker\HaSessionListener;
use Throwable;

final class RecordingSessionListener implements HaSessionListener
{
    /** @var list<StateChange> */
    public array $changes = [];

    /** @var list<HaEvent> */
    public array $events = [];

    /** @var list<TriggerEvent> */
    public array $firedTriggers = [];

    /** @var list<string> */
    public array $rejectedTriggerReasons = [];

    /** @var list<ExposedEntitySync> */
    public array $exposureSyncs = [];

    /** @var list<ExposedEntityCommand> */
    public array $exposureCommands = [];

    /** @var list<string> */
    public array $lost = [];

    /** @var list<Duration> */
    public array $outages = [];

    /** @var list<Throwable> */
    public array $failures = [];

    public bool $failOnReconnect = false;

    public function stateChanged(StateChange $change): void
    {
        $this->changes[] = $change;
    }

    public function eventFired(HaEvent $event): void
    {
        $this->events[] = $event;
    }

    public function triggerFired(TriggerSpec $spec, TriggerEvent $event): void
    {
        $this->firedTriggers[] = $event;
    }

    public function triggerRejected(TriggerSpec $spec, string $reason): void
    {
        $this->rejectedTriggerReasons[] = $reason;
    }

    public function exposedEntitySynced(ExposedEntitySync $sync): void
    {
        $this->exposureSyncs[] = $sync;
    }

    public function exposedEntityCommanded(ExposedEntityCommand $command): void
    {
        $this->exposureCommands[] = $command;
    }

    public function connectionLost(string $reason, Instant $lostAt): void
    {
        $this->lost[] = $reason;
    }

    public function reconnected(Duration $outage): void
    {
        $this->outages[] = $outage;

        if ($this->failOnReconnect) {
            throw new RuntimeException('resync blew up');
        }
    }

    public function connectionFailed(Throwable $error): void
    {
        $this->failures[] = $error;
    }
}
