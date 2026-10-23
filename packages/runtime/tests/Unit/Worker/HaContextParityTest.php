<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\ActionCallError;
use Stewart\Contracts\Exception\ExceptionReason;
use Stewart\Contracts\Exception\IdentifierError;
use Stewart\Contracts\Exception\StateError;
use Stewart\Contracts\Exception\TopicError;
use Stewart\Contracts\Exception\TriggerError;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\History\HistoryQuery;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Trigger\HaTrigger;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\State\StateCache;
use Stewart\Runtime\Tests\Fixtures\Ipc\NullTransport;
use Stewart\Runtime\Tests\Fixtures\Worker\WorkerHaContextFixture;
use Stewart\Runtime\Worker\WorkerHaContext;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\HaContext\RecordingHaContext;
use Stewart\Testing\Time\ManualTimers;

#[CoversClass(WorkerHaContext::class)]
#[CoversClass(RecordingHaContext::class)]
final class HaContextParityTest extends TestCase
{
    use AssertsReason;

    /** @return iterable<string, array{Closure(HaContext): mixed, ExceptionReason}> */
    public static function provideRejectedCalls(): iterable
    {
        yield 'state of a malformed id' => [static fn(HaContext $ha) => $ha->getState('Not An Id'), IdentifierError::EntityIdInvalid];
        yield 'entity of a malformed id' => [static fn(HaContext $ha) => $ha->getEntity('Not An Id'), IdentifierError::EntityIdInvalid];
        yield 'required state of a malformed id' => [static fn(HaContext $ha) => $ha->requireState('Not An Id'), IdentifierError::EntityIdInvalid];
        yield 'history of a malformed id' => [static fn(HaContext $ha) => $ha->getHistory('Not An Id', HistoryQuery::lastFor(Duration::minutes(5))), IdentifierError::EntityIdInvalid];
        yield 'required state that is unknown' => [static fn(HaContext $ha) => $ha->requireState('light.missing'), StateError::EntityNotFound];
        yield 'state changes through events' => [static fn(HaContext $ha) => $ha->watchEvents('state_changed'), StateError::StateChangedViaEvents];
        yield 'empty trigger list' => [static fn(HaContext $ha) => $ha->watchTrigger([]), TriggerError::ListEmpty];
        yield 'trigger without platform' => [static fn(HaContext $ha) => $ha->watchTrigger(['entity_id' => 'light.hall']), TriggerError::ConfigInvalid];
        yield 'trigger variables as list' => [static fn(HaContext $ha) => $ha->watchTrigger(HaTrigger::onSunset(), ['hall']), TriggerError::VariablesNotMap];
        yield 'action without a domain' => [static fn(HaContext $ha) => $ha->callAction('turn_on'), ActionCallError::ActionNameInvalid];
        yield 'response action without a domain' => [static fn(HaContext $ha) => $ha->callActionForResponse('get_forecasts'), ActionCallError::ActionNameInvalid];
        yield 'entity action with its domain' => [static fn(HaContext $ha) => $ha->getEntity('light.hall')->callAction('light.turn_on'), ActionCallError::ActionNameInvalid];
        yield 'non-finite payload' => [static fn(HaContext $ha) => $ha->publish('hall.motion', ['level' => NAN]), TopicError::PayloadInvalid];
        yield 'object payload' => [static fn(HaContext $ha) => $ha->publish('hall.motion', ['level' => [new stdClass()]]), TopicError::PayloadInvalid];
    }

    /** @param Closure(HaContext): mixed $call */
    #[DataProvider('provideRejectedCalls')]
    public function testFakeRejectsWhatProductionRejects(Closure $call, ExceptionReason $reason): void
    {
        $production = WorkerHaContextFixture::createWorkerHaContext(new NullTransport(), ResourceScope::forApp(new AppId('demo')));

        $this->assertThrowsReason($reason, static fn() => $call($production));
        $this->assertThrowsReason($reason, static fn() => $call(new RecordingHaContext()));
    }

    /** @return iterable<string, array{string, Duration, bool, ?Duration}> */
    public static function provideHeldStates(): iterable
    {
        yield 'held longer than asked' => ['light.hall', Duration::minutes(5), true, Duration::minutes(10)];
        yield 'held exactly as long' => ['light.hall', Duration::minutes(10), true, Duration::minutes(10)];
        yield 'not held long enough' => ['light.hall', Duration::minutes(15), false, Duration::minutes(10)];
        yield 'no last change' => ['switch.fan', Duration::zero(), false, null];
        yield 'missing entity' => ['light.missing', Duration::zero(), false, null];
    }

    #[DataProvider('provideHeldStates')]
    public function testHeldStateIsMeasuredAlike(string $entityId, Duration $asked, bool $held, ?Duration $heldFor): void
    {
        $timers = new ManualTimers();
        $states = [
            new EntityState(new EntityId('light.hall'), 'off', lastChangedAt: $timers->clock->getNow()->minus(Duration::minutes(10))),
            new EntityState(new EntityId('switch.fan'), 'on'),
        ];
        $stateCache = new StateCache();
        $stateCache->replaceAll($states);
        $fake = new RecordingHaContext($timers);

        foreach ($states as $state) {
            $fake->pushStateChange(StateChange::fromCurrentState($state));
        }

        foreach ([WorkerHaContextFixture::createWorkerHaContext(new NullTransport(), ResourceScope::forApp(new AppId('demo')), $timers, $stateCache), $fake] as $ha) {
            self::assertSame($held, $ha->hasHeldStateFor($entityId, $asked));
            self::assertEquals($heldFor, $ha->getHeldStateDuration($entityId));
        }
    }
}
