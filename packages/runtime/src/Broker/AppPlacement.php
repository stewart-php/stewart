<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Contracts\App\AppId;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Config\StewartConfig;
use Stewart\Runtime\Model\WorkerId;

final readonly class AppPlacement
{
    public function __construct(private int $autoPoolSize) {}

    public function planWorkerSlots(AppDefinitionCollection $apps, StewartConfig $config): WorkerSlotCollection
    {
        $poolSize = $this->resolvePoolSize($apps, $config->workers);
        $byWorker = [];

        foreach ($apps as $app) {
            $byWorker[$app->worker ?? $this->findHighestWeightWorker($app->id, $poolSize)][] = $app;
        }

        ksort($byWorker);
        $slots = [];

        foreach ($byWorker as $workerId => $workerApps) {
            $slots[] = new WorkerSlot(WorkerId::fromInt($workerId), AppDefinitionCollection::keyedByAppId($workerApps));
        }

        return WorkerSlotCollection::fromList($slots);
    }

    // Rendezvous hashing: an app moves only when the worker it wins is added or removed, never because of other apps.
    private function findHighestWeightWorker(AppId $appId, int $poolSize): int
    {
        $chosen = 0;
        $highestWeight = '';

        for ($workerId = 0; $workerId < $poolSize; ++$workerId) {
            $weight = hash('xxh3', $appId->value . "\0" . $workerId);

            // strcmp, because > compares numeric-looking hex strings as numbers.
            if (strcmp($weight, $highestWeight) > 0) {
                $chosen = $workerId;
                $highestWeight = $weight;
            }
        }

        return $chosen;
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
