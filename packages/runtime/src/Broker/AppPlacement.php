<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Model\WorkerId;

final readonly class AppPlacement
{
    public function __construct(private int $autoPoolSize) {}

    public function planWorkerSlots(AppDefinitionCollection $apps, int $poolWorkerCount): WorkerSlotCollection
    {
        $poolSize = $this->resolvePoolSize($apps, $poolWorkerCount);
        $byWorker = [];
        $next = 0;

        foreach ($apps as $app) {
            $workerId = $app->worker ?? $next++ % $poolSize;
            $byWorker[$workerId][] = $app;
        }

        ksort($byWorker);
        $slots = [];

        foreach ($byWorker as $workerId => $pinned) {
            $slots[] = new WorkerSlot(WorkerId::fromInt($workerId), AppDefinitionCollection::keyedByAppId($pinned));
        }

        return WorkerSlotCollection::fromWorkerSlots($slots);
    }

    private function resolvePoolSize(AppDefinitionCollection $apps, int $workers): int
    {
        if ($workers > 0) {
            return $workers;
        }

        $highestPin = max([-1, ...$apps->mapToList(static fn(AppDefinition $app): int => $app->worker ?? -1)]);

        return max(1, $this->autoPoolSize, $highestPin + 1);
    }
}
