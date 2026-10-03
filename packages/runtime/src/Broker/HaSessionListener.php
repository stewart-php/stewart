<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Throwable;

interface HaSessionListener
{
    public function stateChanged(StateChange $change): void;

    public function eventFired(HaEvent $event): void;

    public function connectionLost(string $reason, Instant $lostAt): void;

    public function reconnected(Duration $outage): void;

    public function connectionFailed(Throwable $error): void;
}
