<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broker;

use RuntimeException;
use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Broker\HaSessionListener;
use Throwable;

final class RecordingSessionListener implements HaSessionListener
{
    /** @var list<StateChange> */
    public array $changes = [];

    /** @var list<HaEvent> */
    public array $events = [];

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
