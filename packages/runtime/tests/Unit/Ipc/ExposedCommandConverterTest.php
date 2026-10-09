<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Ipc;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\JsonShapeError;
use Stewart\Contracts\Exposure\Command\ButtonPress;
use Stewart\Contracts\Exposure\Command\DateCommand;
use Stewart\Contracts\Exposure\Command\DateTimeCommand;
use Stewart\Contracts\Exposure\Command\NumberCommand;
use Stewart\Contracts\Exposure\Command\SelectCommand;
use Stewart\Contracts\Exposure\Command\SwitchAction;
use Stewart\Contracts\Exposure\Command\SwitchCommand;
use Stewart\Contracts\Exposure\Command\TextCommand;
use Stewart\Contracts\Exposure\Command\TimeCommand;
use Stewart\Contracts\Schedule\TimeOfDay;
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

    public function testNumberCommandRoundTrips(): void
    {
        $converter = new ExposedCommandConverter();
        $command = new NumberCommand(2.5, new EventContext('context-1'));

        self::assertEquals($command, $converter->decodeValue($converter->encodeValue($command), 'command'));
    }

    public function testSelectCommandRoundTrips(): void
    {
        $converter = new ExposedCommandConverter();
        $command = new SelectCommand('comfort', new EventContext('context-1'));

        self::assertEquals($command, $converter->decodeValue($converter->encodeValue($command), 'command'));
    }

    public function testNumberCommandWithoutValueIsRejected(): void
    {
        $this->assertThrowsReason(
            JsonShapeError::WrongType,
            static fn() => new ExposedCommandConverter()->decodeValue(['platform' => 'number', 'value' => '2', 'context' => ['id' => 'context-1']], 'command'),
        );
    }

    public function testTextAndCalendarCommandsRoundTrip(): void
    {
        $converter = new ExposedCommandConverter();
        $context = new EventContext('context-1');
        $commands = [
            new TextCommand('Good morning', $context),
            new TimeCommand(TimeOfDay::fromHourMinuteSecond(7, 0), $context),
            new DateCommand(new DateTimeImmutable('2026-10-15', new DateTimeZone('UTC')), $context),
            new DateTimeCommand(new DateTimeImmutable('2026-10-09T18:30:00+02:00'), $context),
        ];

        foreach ($commands as $command) {
            self::assertEquals($command, $converter->decodeValue($converter->encodeValue($command), 'command'));
        }
    }

    public function testDateCommandWithBadValueIsRejected(): void
    {
        $this->assertThrowsReason(
            JsonShapeError::UnexpectedValue,
            static fn() => new ExposedCommandConverter()->decodeValue(['platform' => 'date', 'value' => '2026-13-01', 'context' => ['id' => 'context-1']], 'command'),
        );
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
