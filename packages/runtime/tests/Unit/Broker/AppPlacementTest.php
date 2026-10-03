<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppPlacement;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;

#[CoversClass(AppPlacement::class)]
#[CoversClass(WorkerSlotCollection::class)]
#[CoversClass(WorkerSlot::class)]
final class AppPlacementTest extends TestCase
{
    public function testUnpinnedAppsAreSpreadRoundRobin(): void
    {
        $workerSlots = new AppPlacement(autoPoolSize: 4)->planWorkerSlots(AppDefinitionCollection::keyedByAppId(self::createAppDefinitions(['a', 'b', 'c', 'd'])), poolWorkerCount: 2);

        self::assertSame([[0, ['a', 'c']], [1, ['b', 'd']]], self::describeWorkerSlots($workerSlots));
    }

    public function testOnlyWorkersWithAppsAreSpawned(): void
    {
        $workerSlots = new AppPlacement(autoPoolSize: 4)->planWorkerSlots(AppDefinitionCollection::keyedByAppId(self::createAppDefinitions(['a', 'b'])), poolWorkerCount: 0);

        self::assertSame([[0, ['a']], [1, ['b']]], self::describeWorkerSlots($workerSlots));
    }

    public function testNoAppsMeansNoWorkers(): void
    {
        self::assertTrue(new AppPlacement(autoPoolSize: 4)->planWorkerSlots(AppDefinitionCollection::keyedByAppId([]), poolWorkerCount: 0)->isEmpty());
    }

    public function testPinnedAppGoesWhereItAsked(): void
    {
        $apps = [
            'free' => new AppDefinition(new AppId('free'), Demo::class),
            'pinned' => new AppDefinition(new AppId('pinned'), Demo::class, worker: 1),
        ];

        self::assertSame([[0, ['free']], [1, ['pinned']]], self::describeWorkerSlots(new AppPlacement(4)->planWorkerSlots(AppDefinitionCollection::keyedByAppId($apps), poolWorkerCount: 2)));
    }

    public function testPinGrowsAnAutomaticallySizedPool(): void
    {
        $apps = [
            'a' => new AppDefinition(new AppId('a'), Demo::class),
            'b' => new AppDefinition(new AppId('b'), Demo::class),
            'far' => new AppDefinition(new AppId('far'), Demo::class, worker: 5),
        ];

        $workerSlots = new AppPlacement(autoPoolSize: 1)->planWorkerSlots(AppDefinitionCollection::keyedByAppId($apps), poolWorkerCount: 0);

        self::assertSame([[0, ['a']], [1, ['b']], [5, ['far']]], self::describeWorkerSlots($workerSlots));
    }

    /**
     * @param list<string> $ids
     * @return array<string, AppDefinition>
     */
    private static function createAppDefinitions(array $ids): array
    {
        $apps = [];

        foreach ($ids as $id) {
            $apps[$id] = new AppDefinition(new AppId($id), Demo::class);
        }

        return $apps;
    }

    /** @return list<array{int, list<string>}> */
    private static function describeWorkerSlots(WorkerSlotCollection $workerSlots): array
    {
        return $workerSlots->mapToList(static fn(WorkerSlot $slot): array => [$slot->workerId->value, $slot->listAppIds()->toStrings()]);
    }
}
