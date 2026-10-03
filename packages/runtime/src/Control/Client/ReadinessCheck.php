<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Client;

use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Lifecycle\ConnectionPhase;
use Stewart\Runtime\Lifecycle\WorkerPhase;

final readonly class ReadinessCheck
{
    public function assessSnapshot(RuntimeSnapshot $snapshot): ReadinessVerdict
    {
        $unreadyReasons = [];

        if ($snapshot->connection->phase !== ConnectionPhase::Connected) {
            $unreadyReasons[] = 'Home Assistant is ' . $snapshot->connection->phase->value;
        }

        foreach ($snapshot->workers as $worker) {
            if ($worker->phase === WorkerPhase::Quarantined) {
                $unreadyReasons[] = \sprintf('worker %d is quarantined', $worker->workerId);
            }
        }

        return new ReadinessVerdict($unreadyReasons);
    }
}
