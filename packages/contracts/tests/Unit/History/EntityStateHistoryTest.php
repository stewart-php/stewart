<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\History;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\History\Collection\HistoricalStateCollection;
use Stewart\Contracts\History\EntityStateHistory;
use Stewart\Contracts\History\HistoryWindow;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;

#[CoversClass(EntityStateHistory::class)]
#[CoversClass(HistoricalStateCollection::class)]
final class EntityStateHistoryTest extends TestCase
{
    private const string WINDOW_START = '2026-10-04T12:00:00Z';

    public function testStartStateCountsAsBeenIn(): void
    {
        $history = $this->createHistory([['on', 0]]);

        self::assertTrue($history->hasBeenIn('on'));
        self::assertTrue($history->hasBeenIn('off', 'on'));
        self::assertFalse($history->hasBeenIn('off'));
    }

    public function testStartStateIsNotChange(): void
    {
        $history = $this->createHistory([['on', 0]]);

        self::assertNull($history->getLastChangeTo('on'));
        self::assertSame(0, $history->countChanges());
        self::assertSame('on', $history->getStateAtStart()?->state);
    }

    public function testLastChangeToStateIsLatestEntry(): void
    {
        $history = $this->createHistory([['off', 0], ['on', 10], ['off', 20], ['on', 30]]);

        self::assertSame('2026-10-04T12:30:00.000000Z', (string) $history->getLastChangeTo('on')?->lastChangedAt);
        self::assertSame(3, $history->countChanges());
        self::assertSame('on', $history->getLastState()?->state);
    }

    public function testRepeatedStatesAreNotChanges(): void
    {
        $history = $this->createHistory([['on', 0], ['on', 10], ['off', 20]]);

        self::assertSame(1, $history->countChanges());
        self::assertNull($history->getLastChangeTo('on'));
    }

    public function testDurationInStateIsClampedToWindow(): void
    {
        $history = $this->createHistory([['on', -30], ['off', 10], ['on', 50]]);

        self::assertTrue($history->getDurationIn('on')->equals(Duration::minutes(20)));
        self::assertTrue($history->getDurationIn('off')->equals(Duration::minutes(40)));
        self::assertTrue($history->getDurationIn('unavailable')->equals(Duration::zero()));
    }

    public function testEmptyHistoryAnswersNothing(): void
    {
        $history = $this->createHistory([]);

        self::assertTrue($history->isEmpty());
        self::assertCount(0, $history);
        self::assertNull($history->getStateAtStart());
        self::assertFalse($history->hasBeenIn('on'));
        self::assertTrue($history->getDurationIn('on')->equals(Duration::zero()));
    }

    /** @param list<array{string, int}> $entries state and minutes after the window start */
    private function createHistory(array $entries): EntityStateHistory
    {
        $entityId = EntityId::fromStringOrId('light.hall');
        $start = Instant::fromIso(self::WINDOW_START);
        $states = [];

        foreach ($entries as [$state, $minutes]) {
            $changedAt = $minutes < 0 ? $start->minus(Duration::minutes(-$minutes)) : $start->plus(Duration::minutes($minutes));
            $states[] = new EntityState($entityId, $state, lastChangedAt: $changedAt);
        }

        return new EntityStateHistory(
            $entityId,
            new HistoryWindow($start, $start->plus(Duration::hours(1))),
            HistoricalStateCollection::fromStates($states),
        );
    }
}
