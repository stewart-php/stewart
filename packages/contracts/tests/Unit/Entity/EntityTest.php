<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\Entity;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\StateError;
use Stewart\Contracts\Service\ServiceTarget;
use Stewart\Contracts\State\StateChange;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\HaContext\RecordingHaContext;

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

    public function testUnknownEntityStateIsNullRequireThrows(): void
    {
        $entity = new RecordingHaContext()->getEntity('light.missing');

        self::assertNull($entity->getState());

        $this->assertThrowsReason(StateError::EntityNotFound, fn() => $entity->requireState());
    }

    public function testCallsTargetItsOwnDomainAndItself(): void
    {
        $ha = new RecordingHaContext();

        $ha->getEntity('light.hall')->callService('turn_on', ['brightness' => 180]);

        self::assertSame('light.turn_on', $ha->getLastCall()->getServiceName());
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
        $ha = new RecordingHaContext()->stubServiceAnswer('weather', 'get_forecasts', ['forecast' => []]);

        $response = $ha->getEntity('weather.home')->callServiceForResponse('get_forecasts', ['type' => 'daily']);

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

        $ha->getEntity('light.hall')->callService('turn_on', ['brightness' => null, 'transition' => 2]);

        self::assertSame(['transition' => 2], $ha->getLastCall()->data);
    }
}
