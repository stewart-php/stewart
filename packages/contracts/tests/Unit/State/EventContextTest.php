<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\State;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\EventContext;
use Stewart\Contracts\State\StateChange;

#[CoversClass(EventContext::class)]
#[CoversClass(StateChange::class)]
#[CoversClass(EntityState::class)]
final class EventContextTest extends TestCase
{
    public function testMatchesSameIdOrChildContext(): void
    {
        $call = new EventContext('call-1');

        self::assertTrue($call->isSameOrParentOf(new EventContext('call-1')));
        self::assertTrue($call->isSameOrParentOf(new EventContext('script-run', parentId: 'call-1')));
        self::assertFalse($call->isSameOrParentOf(new EventContext('call-2', parentId: 'other')));
    }

    public function testEmptyIdMatchesNothing(): void
    {
        self::assertFalse(new EventContext('')->isSameOrParentOf(new EventContext('')));
    }

    public function testStateChangeFallsBackToNewStateContext(): void
    {
        $call = new EventContext('call-1');
        $state = new EntityState(new EntityId('light.hall'), 'on', context: new EventContext('call-1'));

        self::assertTrue(new StateChange(new EntityId('light.hall'), null, $state, context: new EventContext('call-1'))->wasCausedBy($call));
        self::assertTrue(new StateChange(new EntityId('light.hall'), null, $state)->wasCausedBy($call));
        self::assertFalse(new StateChange(new EntityId('light.hall'), $state, null)->wasCausedBy($call));
    }

    public function testEntityStateKnowsItsLastChange(): void
    {
        $call = new EventContext('call-1');

        self::assertTrue(new EntityState(new EntityId('light.hall'), 'on', context: new EventContext('call-1'))->wasLastChangedBy($call));
        self::assertFalse(new EntityState(new EntityId('light.hall'), 'on', context: new EventContext('manual'))->wasLastChangedBy($call));
        self::assertFalse(new EntityState(new EntityId('light.hall'), 'on')->wasLastChangedBy($call));
    }
}
