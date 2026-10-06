<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Identity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Event\EventOrigin;
use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\Identity\StewartIdentity;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\EventContext;
use Stewart\Contracts\State\StateChange;

#[CoversClass(StewartIdentity::class)]
final class StewartIdentityTest extends TestCase
{
    public function testChangeByStewartUserIsRecognised(): void
    {
        $identity = new StewartIdentity('stewart-user');
        $byStewart = new EntityState(new EntityId('light.hall'), 'on', context: new EventContext('c1', userId: 'stewart-user'));

        self::assertTrue($identity->wasCausedByStewart($byStewart));
        self::assertTrue($identity->wasCausedByStewart(new StateChange(new EntityId('light.hall'), null, $byStewart)));
    }

    public function testManualAndPhysicalChangesAreNotStewart(): void
    {
        $identity = new StewartIdentity('stewart-user');

        self::assertFalse($identity->wasCausedByStewart(new EntityState(new EntityId('light.hall'), 'on', context: new EventContext('c1', userId: 'someone'))));
        self::assertFalse($identity->wasCausedByStewart(new EntityState(new EntityId('light.hall'), 'on', context: new EventContext('c2'))));
        self::assertFalse($identity->wasCausedByStewart(new EntityState(new EntityId('light.hall'), 'on')));
    }

    public function testEventFiredByStewartUserIsRecognised(): void
    {
        $identity = new StewartIdentity('stewart-user');

        self::assertTrue($identity->wasCausedByStewart(new HaEvent('doorbell_pressed', origin: EventOrigin::Remote, context: new EventContext('c1', userId: 'stewart-user'))));
        self::assertFalse($identity->wasCausedByStewart(new HaEvent('doorbell_pressed', origin: EventOrigin::Remote, context: new EventContext('c2', userId: 'someone'))));
        self::assertFalse($identity->wasCausedByStewart(new HaEvent('doorbell_pressed')));
    }

    public function testUnknownUserMatchesNothing(): void
    {
        $state = new EntityState(new EntityId('light.hall'), 'on', context: new EventContext('c1'));

        self::assertFalse(new StewartIdentity(null)->wasCausedByStewart($state));
        self::assertFalse(new StewartIdentity('')->wasCausedByStewart(new EntityState(new EntityId('light.hall'), 'on', context: new EventContext('c1', userId: ''))));
    }
}
