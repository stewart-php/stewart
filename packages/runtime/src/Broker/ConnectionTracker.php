<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Control\Protocol\Status\ConnectionState;
use Stewart\Runtime\Ipc\Message\HaConnectionLost;
use Stewart\Runtime\Lifecycle\ConnectionPhase;

final class ConnectionTracker
{
    public private(set) ConnectionState $state;

    public private(set) ?HaConnectionLost $lastLoss = null;

    public function __construct(private readonly Clock $clock)
    {
        $this->state = new ConnectionState();
    }

    public function markConnected(): void
    {
        $this->state = new ConnectionState(ConnectionPhase::Connected, $this->clock->getNow());
    }

    public function markReconnecting(HaConnectionLost $loss): void
    {
        $this->lastLoss = $loss;
        $this->state = new ConnectionState(ConnectionPhase::Reconnecting, $this->clock->getNow(), $this->state->reconnects, $this->state->lastOutage);
    }

    public function markRestored(Duration $outage): void
    {
        $this->lastLoss = null;
        $this->state = new ConnectionState(ConnectionPhase::Connected, $this->clock->getNow(), $this->state->reconnects + 1, $outage);
    }
}
