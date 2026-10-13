<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Exposure\Action;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exposure\Action\ActionCall;
use Stewart\Contracts\State\EventContext;

#[CoversClass(ActionCall::class)]
final class ActionCallTest extends TestCase
{
    public function testFieldsAreReadByType(): void
    {
        $call = new ActionCall(['room' => 'climate.office', 'minutes' => 45.0, 'quiet' => true], new EventContext('context-1'));

        self::assertSame('climate.office', $call->findString('room'));
        self::assertSame('climate.office', $call->findEntityId('room')?->value);
        self::assertSame(45.0, $call->findNumber('minutes'));
        self::assertTrue($call->findBool('quiet'));
        self::assertNull($call->getRequestedState());
    }

    public function testMissingOrMistypedFieldIsNull(): void
    {
        $call = new ActionCall(['minutes' => '45', 'room' => 'office'], new EventContext('context-1'));

        self::assertNull($call->findNumber('minutes'));
        self::assertNull($call->findBool('quiet'));
        self::assertNull($call->findEntityId('room'));
        self::assertNull($call->findString('absent'));
    }
}
