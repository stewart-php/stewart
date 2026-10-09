<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Exposure\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exposure\Command\ButtonPress;
use Stewart\Contracts\Exposure\Command\SwitchAction;
use Stewart\Contracts\Exposure\Command\SwitchCommand;
use Stewart\Contracts\State\EventContext;

#[CoversClass(SwitchCommand::class)]
#[CoversClass(ButtonPress::class)]
final class SwitchCommandTest extends TestCase
{
    public function testTurnOnRequestsOn(): void
    {
        $command = new SwitchCommand(SwitchAction::TurnOn, new EventContext('context-1'));

        self::assertTrue($command->isTurnOn());
        self::assertTrue($command->getRequestedState()->value);
    }

    public function testTurnOffRequestsOff(): void
    {
        self::assertFalse(new SwitchCommand(SwitchAction::TurnOff, new EventContext('context-1'))->getRequestedState()->value);
    }

    public function testPressRequestsNoState(): void
    {
        self::assertNull(new ButtonPress(new EventContext('context-1'))->getRequestedState());
    }
}
