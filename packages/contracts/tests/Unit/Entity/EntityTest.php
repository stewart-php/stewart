<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\Entity;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\StateError;
use Stewart\Contracts\History\HistoryQuery;
use Stewart\Contracts\Service\ServiceTarget;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\HaContext\RecordingHaContext;
use Stewart\Testing\Time\EventLoopTicks;

use function Amp\async;

#[CoversClass(Entity::class)]
#[CoversClass(ServiceTarget::class)]
final class EntityTest extends TestCase
{
    use AssertsReason;

    public function testSplitsItsIdAndReadsThroughTheContext(): void
    {
        $ha = new RecordingHaContext()->seedState('light.hall', 'on', ['brightness' => 180]);
        $entity = $ha->getEntity('light.hall');

        self::assertSame('light', $entity->getDomain());
        self::assertSame('hall', $entity->getObjectId());
        self::assertSame('on', $entity->requireState()->state);
        self::assertSame(180, $entity->getState()?->getAttribute('brightness'));
        self::assertEquals([new EntityId('light.hall')], $entity->toServiceTarget()->entityIds);
    }

    public function testHistoryIsReadThroughContext(): void
    {
        $ha = new RecordingHaContext()->seedState('light.hall', 'on');
        $ha->clock->skip(Duration::minutes(1));

        $history = $ha->getEntity('light.hall')->getHistory(HistoryQuery::lastFor(Duration::minutes(5)));

        self::assertSame('light.hall', $ha->historyQueries->getLast()?->entityId->value);
        self::assertTrue($history->hasBeenIn('on'));
    }

    public function testUnknownEntityStateIsNullRequireThrows(): void
    {
        $entity = new RecordingHaContext()->getEntity('light.missing');

        self::assertNull($entity->getState());

        $this->assertThrowsReason(StateError::EntityNotFound, fn() => $entity->requireState());
    }

    public function testWaitForAStateTheEntityIsAlreadyInReturnsAtOnce(): void
    {
        $ha = new RecordingHaContext()->seedState('lock.front', 'locked');

        self::assertSame('locked', $ha->getEntity('lock.front')->waitForState('locked')?->state);
    }

    public function testWaitEndsWhenTheEntityReachesTheState(): void
    {
        $ha = new RecordingHaContext()->seedState('lock.front', 'unlocked');
        $outcome = async(static fn(): ?EntityState => $ha->getEntity('lock.front')->waitForState('locked', Duration::seconds(10)));
        EventLoopTicks::settle();

        $ha->pushState('lock.front', 'locking');
        $ha->pushState('lock.kitchen', 'locked');
        $ha->pushState('lock.front', 'locked');

        $state = $outcome->await();
        self::assertInstanceOf(EntityState::class, $state);
        self::assertSame('locked', $state->state);
    }

    public function testWaitIgnoresTheEntityBeingRemoved(): void
    {
        $ha = new RecordingHaContext()->seedState('lock.front', 'unlocked');
        $outcome = async(static fn(): ?EntityState => $ha->getEntity('lock.front')->waitForState('locked'));
        EventLoopTicks::settle();

        $ha->pushStateChange(new StateChange(new EntityId('lock.front'), new EntityState(new EntityId('lock.front'), 'unlocked'), null));
        $ha->pushState('lock.front', 'locked');

        $state = $outcome->await();
        self::assertInstanceOf(EntityState::class, $state);
        self::assertSame('locked', $state->state);
    }

    public function testWaitEndsWithNullOnceItsTimeoutPasses(): void
    {
        $ha = new RecordingHaContext()->seedState('lock.front', 'unlocked');
        $outcome = async(static fn(): ?EntityState => $ha->getEntity('lock.front')->waitForState('locked', Duration::seconds(10)));
        EventLoopTicks::settle();

        $ha->timers->delay(Duration::seconds(10));

        self::assertNull($outcome->await());
    }

    public function testCallsTargetItsOwnDomainAndItself(): void
    {
        $ha = new RecordingHaContext();

        $context = $ha->getEntity('light.hall')->callAction('turn_on', ['brightness' => 180]);

        self::assertSame($ha->getLastCall()->context, $context);
        self::assertSame('light.turn_on', $ha->getLastCall()->getActionName());
        self::assertSame(['brightness' => 180], $ha->getLastCall()->data);
        self::assertSame(['light.hall'], $ha->getLastCall()->listTargetedEntityIds());
        self::assertFalse($ha->getLastCall()->returnsResponse);
    }

    public function testStateChangesAreScopedToTheEntity(): void
    {
        $ha = new RecordingHaContext();
        $seen = [];

        $ha->getEntity('light.hall')->watchStateChanges()->subscribe(static function (StateChange $change) use (&$seen): void {
            $seen[] = $change->to?->state;
        });

        $ha->pushState('light.kitchen', 'on');
        $ha->pushState('light.hall', 'on');

        self::assertSame(['on'], $seen);
    }

    public function testResponseCallAsksForResponse(): void
    {
        $ha = new RecordingHaContext()->stubActionAnswer('weather.get_forecasts', ['forecast' => []]);

        $response = $ha->getEntity('weather.home')->callActionForResponse('get_forecasts', ['type' => 'daily']);

        self::assertTrue($ha->getLastCall()->returnsResponse);
        self::assertSame(['forecast' => []], $response->response);
    }

    public function testTargetSourcesConvertToServiceTarget(): void
    {
        $ha = new RecordingHaContext();

        self::assertEquals([new EntityId('light.hall')], $ha->getEntity('light.hall')->toServiceTarget()->entityIds);
        self::assertSame(['area.kitchen'], ServiceTarget::forAreas('area.kitchen')->toServiceTarget()->areaIds);
    }

    public function testNullFieldsAreDroppedFromCalls(): void
    {
        $ha = new RecordingHaContext();

        $ha->getEntity('light.hall')->callAction('turn_on', ['brightness' => null, 'transition' => 2]);

        self::assertSame(['transition' => 2], $ha->getLastCall()->data);
    }


    #[IgnoreDeprecations]
    public function testDeprecatedCallServiceStillCallsTheAction(): void
    {
        $ha = new RecordingHaContext();
        $this->expectUserDeprecationMessageMatches('/Entity::callService\(\) is deprecated since 1\.8, use callAction/');

        $ha->getEntity('light.hall')->callService('turn_on');

        self::assertSame('light.turn_on', $ha->getLastCall()->getActionName());
    }

    #[IgnoreDeprecations]
    public function testDeprecatedCallServiceForResponseStillAsksForTheResponse(): void
    {
        $ha = new RecordingHaContext()->stubActionAnswer('weather.get_forecasts', ['forecast' => []]);
        $this->expectUserDeprecationMessageMatches('/Entity::callServiceForResponse\(\) is deprecated since 1\.8, use callActionForResponse/');

        self::assertSame(['forecast' => []], $ha->getEntity('weather.home')->callServiceForResponse('get_forecasts')->response);
    }
}
