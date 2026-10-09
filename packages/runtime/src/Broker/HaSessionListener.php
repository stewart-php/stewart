<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Trigger\TriggerEvent;
use Stewart\Contracts\Trigger\TriggerSpec;
use Stewart\Runtime\Broker\Exposure\ExposedEntitySync;
use Throwable;

interface HaSessionListener
{
    public function stateChanged(StateChange $change): void;

    public function eventFired(HaEvent $event): void;

    public function triggerFired(TriggerSpec $spec, TriggerEvent $event): void;

    public function triggerRejected(TriggerSpec $spec, string $reason): void;

    public function exposedEntitySynced(ExposedEntitySync $sync): void;

    public function connectionLost(string $reason, Instant $lostAt): void;

    public function reconnected(Duration $outage): void;

    public function connectionFailed(Throwable $error): void;
}
