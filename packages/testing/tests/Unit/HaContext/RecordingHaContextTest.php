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
use Stewart\Contracts\History\HistoryDetail;
use Stewart\Contracts\History\HistoryQuery;
use Stewart\Contracts\Identity\StewartIdentity;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\EventContext;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Trigger\HaTrigger;
use Stewart\Contracts\Trigger\TriggerEvent;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\HaContext\RecordingHaContext;
use Stewart\Testing\HaContext\SeededHistory;

#[CoversClass(RecordingHaContext::class)]
#[CoversClass(SeededHistory::class)]
final class RecordingHaContextTest extends TestCase
{
    use AssertsReason;

    public function testPushedTriggerReachesOnlyEqualSpec(): void
    {
        $ha = new RecordingHaContext();
        $sunsets = [];
        $sunrises = [];
        $ha->watchTrigger(HaTrigger::onSunset(), ['room' => 'hall'])->subscribe(static function (TriggerEvent $event) use (&$sunsets): void {
            $sunsets[] = $event->getTriggerId();
        });
        $ha->watchTrigger(['trigger' => 'sun', 'event' => 'sunrise'])->subscribe(static function (TriggerEvent $event) use (&$sunrises): void {
            $sunrises[] = $event->getTriggerId();
        });

        $ha->pushTrigger(HaTrigger::onSunset(), new TriggerEvent(['platform' => 'sun', 'id' => 'dusk']));

        self::assertSame(['dusk'], $sunsets);
        self::assertSame([], $sunrises);
    }

    public function testWatchedTriggersAreRecorded(): void
    {
        $ha = new RecordingHaContext();

        $ha->watchTrigger(HaTrigger::atTime('07:30'));

        self::assertCount(1, $ha->listWatchedTriggers());
        self::assertSame([['trigger' => 'time', 'at' => '07:30']], $ha->listWatchedTriggers()->getFirst()?->listTriggerConfigs());
    }

    public function testEachCallGetsItsOwnStewartContext(): void
    {
        $ha = new RecordingHaContext();

        $first = $ha->callService('light', 'turn_on');
        $second = $ha->callServiceForResponse('weather', 'get_forecasts')->context;

        self::assertSame('recorded-1', $first->id);
        self::assertSame('recorded-2', $second?->id);
        self::assertSame(RecordingHaContext::STEWART_USER_ID, $first->userId);
        self::assertSame($first, $ha->calls->getFirst()?->context);
    }

    public function testPushedStateCarriesCausingContext(): void
    {
        $ha = new RecordingHaContext();
        $identity = new StewartIdentity(RecordingHaContext::STEWART_USER_ID);
        $changes = [];
        $ha->watchStateChanges('light.hall')->subscribe(static function (StateChange $change) use (&$changes): void {
            $changes[] = $change;
        });
        $call = $ha->callService('light', 'turn_on');

        $ha->pushState('light.hall', 'on', context: $call);
        $ha->pushState('light.hall', 'off', context: new EventContext('manual', userId: 'resident'));

        self::assertTrue($changes[0]->wasCausedBy($call));
        self::assertTrue($identity->wasCausedByStewart($changes[0]));
        self::assertFalse($changes[1]->wasCausedBy($call));
        self::assertFalse($identity->wasCausedByStewart($changes[1]));
        self::assertTrue($ha->requireState('light.hall')->wasLastChangedBy(new EventContext('manual')));
    }

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
        self::assertSame(HistoryDetail::StateChangesWithAttributes, $context->historyQueries->getLast()?->detail);
    }

    public function testAttributeOnlyChangesAppearOnlyWhenAsked(): void
    {
        $context = new RecordingHaContext();
        $context->seedState('light.hall', 'on', ['brightness' => 50]);
        $context->clock->skip(Duration::minutes(1));
        $context->pushState('light.hall', 'on', ['brightness' => 200]);
        $context->clock->skip(Duration::minutes(1));
        $query = HistoryQuery::lastFor(Duration::minutes(5));

        $brightness = static fn(EntityState $state): mixed => $state->getAttribute('brightness');

        self::assertSame([50], $context->getHistory('light.hall', $query->withAttributes())->states->mapToList($brightness));
        self::assertSame([50, 200], $context->getHistory('light.hall', $query->withAttributeChanges())->states->mapToList($brightness));
        self::assertSame(0, $context->getHistory('light.hall', $query->withAttributeChanges())->countChanges());
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
