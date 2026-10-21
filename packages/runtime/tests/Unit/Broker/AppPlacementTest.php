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
use Stewart\Runtime\Tests\Fixtures\Config\ConfigFixture;

#[CoversClass(AppPlacement::class)]
#[CoversClass(WorkerSlotCollection::class)]
#[CoversClass(WorkerSlot::class)]
final class AppPlacementTest extends TestCase
{
    public function testUnpinnedAppsArePlacedByTheirId(): void
    {
        $workerSlots = self::planWorkerSlots(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'], workers: 4);

        self::assertSame([[0, ['a', 'd', 'e', 'f']], [1, ['c']], [3, ['b', 'g', 'h']]], self::describeWorkerSlots($workerSlots));
    }

    public function testAddingAnAppMovesNoOther(): void
    {
        $before = self::planWorkerSlots(['a', 'b', 'c', 'd', 'e'], workers: 4);
        $after = self::planWorkerSlots(['a', 'b', 'c', 'd', 'e', 'f', 'g'], workers: 4);

        foreach (['a', 'b', 'c', 'd', 'e'] as $id) {
            self::assertSame(self::findWorkerOf($before, $id), self::findWorkerOf($after, $id), $id);
        }
    }

    public function testGrowingThePoolMovesAppsOnlyToTheNewWorker(): void
    {
        $ids = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];
        $before = self::planWorkerSlots($ids, workers: 3);
        $after = self::planWorkerSlots($ids, workers: 4);
        $moved = [];

        foreach ($ids as $id) {
            $worker = self::findWorkerOf($after, $id);

            if ($worker !== self::findWorkerOf($before, $id)) {
                $moved[$id] = $worker;
            }
        }

        self::assertSame(['b' => 3, 'g' => 3, 'h' => 3], $moved);
    }

    public function testPlacementIgnoresInputOrder(): void
    {
        $ids = ['a', 'b', 'c', 'd', 'e', 'f'];
        $forwards = self::planWorkerSlots($ids, workers: 4);
        $backwards = self::planWorkerSlots(array_reverse($ids), workers: 4);

        foreach ($ids as $id) {
            self::assertSame(self::findWorkerOf($forwards, $id), self::findWorkerOf($backwards, $id), $id);
        }
    }

    public function testOnlyWorkersWithAppsAreSpawned(): void
    {
        $workerSlots = self::planWorkerSlots(['a', 'b'], workers: 0);

        self::assertSame([[0, ['a']], [3, ['b']]], self::describeWorkerSlots($workerSlots));
    }

    public function testNoAppsMeansNoWorkers(): void
    {
        self::assertTrue(self::planWorkerSlots([], workers: 0)->isEmpty());
    }

    public function testPinnedAppGoesWhereItAsked(): void
    {
        $apps = [
            'free' => new AppDefinition(new AppId('free'), Demo::class),
            'pinned' => new AppDefinition(new AppId('pinned'), Demo::class, worker: 1),
        ];

        self::assertSame([[1, ['free', 'pinned']]], self::describeWorkerSlots(new AppPlacement(4)->planWorkerSlots(AppDefinitionCollection::keyedByAppId($apps), ConfigFixture::createStewartConfig(['workers' => 2]))));
    }

    public function testPinGrowsAnAutomaticallySizedPool(): void
    {
        $apps = [
            'a' => new AppDefinition(new AppId('a'), Demo::class),
            'b' => new AppDefinition(new AppId('b'), Demo::class),
            'far' => new AppDefinition(new AppId('far'), Demo::class, worker: 5),
        ];

        $workerSlots = new AppPlacement(autoPoolSize: 1)->planWorkerSlots(AppDefinitionCollection::keyedByAppId($apps), ConfigFixture::createStewartConfig(['workers' => 0]));

        self::assertSame([[3, ['b']], [4, ['a']], [5, ['far']]], self::describeWorkerSlots($workerSlots));
    }

    /** @param list<string> $ids */
    private static function planWorkerSlots(array $ids, int $workers): WorkerSlotCollection
    {
        return new AppPlacement(autoPoolSize: 4)->planWorkerSlots(AppDefinitionCollection::keyedByAppId(self::createAppDefinitions($ids)), ConfigFixture::createStewartConfig(['workers' => $workers]));
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

    private static function findWorkerOf(WorkerSlotCollection $workerSlots, string $id): ?int
    {
        return $workerSlots->findWorkerOfApp(new AppId($id))?->value;
    }

    /** @return list<array{int, list<string>}> */
    private static function describeWorkerSlots(WorkerSlotCollection $workerSlots): array
    {
        return $workerSlots->mapToList(static fn(WorkerSlot $slot): array => [$slot->workerId->value, $slot->listAppIds()->toStrings()]);
    }
}
