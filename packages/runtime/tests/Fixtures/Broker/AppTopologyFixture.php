<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broker;

use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Runtime\App\AppCatalog;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppTopology;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\WorkerSlot;

final class AppTopologyFixture
{
    private function __construct() {}

    public static function createTopology(?WorkerSlotCollection $workerSlots = null, ?AppIdCollection $knownAppIds = null): AppTopology
    {
        $workerSlots ??= WorkerSlotCollection::empty();
        $apps = [];

        foreach ($workerSlots as $slot) {
            foreach ($slot->apps as $app) {
                $apps[] = $app;
            }
        }

        $enabled = AppDefinitionCollection::keyedByAppId($apps);

        return new AppTopology(new AppCatalog($enabled, $knownAppIds ?? $enabled->listAppIds(), AppIdCollection::empty()), $workerSlots);
    }

    public static function createTopologyForSlots(WorkerSlot ...$workerSlots): AppTopology
    {
        return self::createTopology(WorkerSlotCollection::fromList($workerSlots));
    }
}
