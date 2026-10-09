<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Ipc;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\JsonShapeError;
use Stewart\Contracts\Exposure\Command\ButtonPress;
use Stewart\Contracts\Exposure\Command\SwitchAction;
use Stewart\Contracts\Exposure\Command\SwitchCommand;
use Stewart\Contracts\State\EventContext;
use Stewart\Runtime\Ipc\Wire\ExposedCommandConverter;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(ExposedCommandConverter::class)]
final class ExposedCommandConverterTest extends TestCase
{
    use AssertsReason;

    public function testSwitchCommandRoundTrips(): void
    {
        $converter = new ExposedCommandConverter();
        $command = new SwitchCommand(SwitchAction::TurnOff, new EventContext('context-1', 'parent-1', 'user-1'));

        self::assertEquals($command, $converter->decodeValue($converter->encodeValue($command), 'command'));
    }

    public function testButtonPressRoundTrips(): void
    {
        $converter = new ExposedCommandConverter();
        $command = new ButtonPress(new EventContext('context-1'));

        self::assertEquals($command, $converter->decodeValue($converter->encodeValue($command), 'command'));
    }

    public function testPlatformWithoutCommandsIsRejected(): void
    {
        $this->assertThrowsReason(
            JsonShapeError::UnexpectedValue,
            static fn() => new ExposedCommandConverter()->decodeValue(['platform' => 'sensor', 'context' => ['id' => 'context-1']], 'command'),
        );
    }

    public function testUnknownSwitchActionIsRejected(): void
    {
        $this->assertThrowsReason(
            JsonShapeError::UnexpectedValue,
            static fn() => new ExposedCommandConverter()->decodeValue(['platform' => 'switch', 'action' => 'toggle', 'context' => ['id' => 'context-1']], 'command'),
        );
    }
}
