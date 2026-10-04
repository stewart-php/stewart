<?php

declare(strict_types=1);

namespace Stewart\Testing\Tests\Unit\HaContext;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Connection\ConnectionLost;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\HistoryError;
use Stewart\Contracts\Exception\HistoryException;
use Stewart\Contracts\Exception\IdentifierError;
use Stewart\Contracts\Exception\TopicError;
use Stewart\Contracts\History\HistoryQuery;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\HaContext\RecordingHaContext;
use Stewart\Testing\HaContext\SeededHistory;

#[CoversClass(RecordingHaContext::class)]
#[CoversClass(SeededHistory::class)]
final class RecordingHaContextTest extends TestCase
{
    use AssertsReason;

    public function testPublishRejectsNonFinitePayload(): void
    {
        $this->assertThrowsReason(TopicError::PayloadInvalid, fn() => new RecordingHaContext()->publish('hall.motion', ['level' => NAN]));
    }

    public function testStateRejectsInvalidEntityId(): void
    {
        $this->assertThrowsReason(IdentifierError::EntityIdInvalid, fn() => new RecordingHaContext()->getState('Not An Id'));
    }

    public function testRequireStateRejectsInvalidEntityId(): void
    {
        $this->assertThrowsReason(IdentifierError::EntityIdInvalid, fn() => new RecordingHaContext()->requireState('Not An Id'));
    }

    public function testPublishRecordsValidatedPayload(): void
    {
        $context = new RecordingHaContext();

        $context->publish('hall.motion', ['on' => true]);

        self::assertSame(['on' => true], $context->published->getFirst()?->payload);
    }

    public function testHistoryStartsWithStateAtWindowStart(): void
    {
        $context = new RecordingHaContext();
        $now = $context->clock->getNow();
        $context
            ->seedHistoricalState('light.hall', 'on', $now->minus(Duration::hours(2)))
            ->seedHistoricalState('light.hall', 'off', $now->minus(Duration::hours(1)))
            ->seedHistoricalState('light.hall', 'on', $now->minus(Duration::minutes(10)));

        $history = $context->getHistory('light.hall', HistoryQuery::lastFor(Duration::minutes(30)));

        self::assertSame(['off', 'on'], $history->states->mapToList(static fn(EntityState $state): string => $state->state));
        self::assertTrue($history->getStateAtStart()?->lastChangedAt?->equals($history->window->startsAt));
        self::assertTrue($history->getDurationIn('on')->equals(Duration::minutes(10)));
    }

    public function testPushedStatesBecomeHistory(): void
    {
        $context = new RecordingHaContext();
        $context->seedState('light.hall', 'off');
        $context->clock->skip(Duration::minutes(5));
        $context->pushState('light.hall', 'on');
        $context->clock->skip(Duration::minutes(5));

        $history = $context->getHistory('light.hall', HistoryQuery::lastFor(Duration::minutes(8)));

        self::assertSame(1, $history->countChanges());
        self::assertTrue($history->getDurationIn('on')->equals(Duration::minutes(5)));
    }

    public function testHistoryLeavesAttributesOutUnlessAsked(): void
    {
        $context = new RecordingHaContext();
        $context->seedState('light.hall', 'on', ['brightness' => 120]);
        $context->clock->skip(Duration::minutes(1));

        self::assertSame([], $context->getHistory('light.hall', HistoryQuery::lastFor(Duration::minutes(5)))->getLastState()?->attributes);
        self::assertSame(['brightness' => 120], $context->getHistory('light.hall', HistoryQuery::lastFor(Duration::minutes(5))->withAttributes())->getLastState()?->attributes);
        self::assertCount(2, $context->historyQueries);
        self::assertTrue($context->historyQueries->getLast()?->includesAttributes);
    }

    public function testStubbedHistoryFailureIsThrown(): void
    {
        $context = new RecordingHaContext()->stubHistoryFailure('light.hall', HistoryException::recorderUnavailable(new EntityId('light.hall')));

        $this->assertThrowsReason(HistoryError::RecorderUnavailable, static fn() => $context->getHistory('light.hall', HistoryQuery::lastFor(Duration::minutes(5))));
    }

    public function testHistoryWhileDisconnectedIsUnreachable(): void
    {
        $context = new RecordingHaContext();
        $context->pushConnection(new ConnectionLost($context->clock->getNow(), 'gone'));

        $this->assertThrowsReason(HistoryError::Unreachable, static fn() => $context->getHistory('light.hall', HistoryQuery::lastFor(Duration::minutes(5))));
    }
}
