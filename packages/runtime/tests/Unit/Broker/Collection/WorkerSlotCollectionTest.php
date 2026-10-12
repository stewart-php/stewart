<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker\Collection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Exception\WorkerError;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(WorkerSlotCollection::class)]
final class WorkerSlotCollectionTest extends TestCase
{
    use AssertsReason;

    public function testSlotIsFoundByItsWorker(): void
    {
        $second = new WorkerSlot(new WorkerId(1), AppDefinitionCollection::keyedByAppId([]));
        $slots = WorkerSlotCollection::fromList([new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([])), $second]);

        self::assertSame($second, $slots->requireSlotById('1'));
    }

    public function testWorkerOutsideThePoolIsUnknown(): void
    {
        $slots = WorkerSlotCollection::fromList([new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([]))]);

        $this->assertThrowsReason(WorkerError::Unknown, static fn() => $slots->requireSlotById('3'));
    }

    public function testIdThatIsNotAPlainNumberIsUnknown(): void
    {
        $slots = WorkerSlotCollection::fromList([new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([]))]);

        foreach (['', '00', ' 0', '-0', 'porch'] as $workerId) {
            $this->assertThrowsReason(WorkerError::Unknown, static fn() => $slots->requireSlotById($workerId));
        }
    }
}
