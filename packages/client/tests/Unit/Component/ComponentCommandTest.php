<?php

declare(strict_types=1);

namespace Stewart\Client\Tests\Unit\Component;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Client\Component\ComponentCommand;
use Stewart\Client\Component\ComponentCommandAction;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exposure\Command\ButtonPress;
use Stewart\Contracts\Exposure\Command\DateCommand;
use Stewart\Contracts\Exposure\Command\DateTimeCommand;
use Stewart\Contracts\Exposure\Command\NumberCommand;
use Stewart\Contracts\Exposure\Command\SelectCommand;
use Stewart\Contracts\Exposure\Command\SwitchAction;
use Stewart\Contracts\Exposure\Command\SwitchCommand;
use Stewart\Contracts\Exposure\Command\TextCommand;
use Stewart\Contracts\Exposure\Command\TimeCommand;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedPlatform;
use Stewart\Contracts\Schedule\TimeOfDay;
use Stewart\Contracts\State\EventContext;

#[CoversClass(ComponentCommand::class)]
final class ComponentCommandTest extends TestCase
{
    public function testSwitchActionsBecomeSwitchCommands(): void
    {
        $command = self::createCommand(ComponentCommandAction::TurnOff)->readExposedCommand(ExposedPlatform::Switch);

        self::assertEquals(new SwitchCommand(SwitchAction::TurnOff, self::createContext()), $command);
    }

    public function testPressBecomesButtonPress(): void
    {
        $command = self::createCommand(ComponentCommandAction::Press)->readExposedCommand(ExposedPlatform::Button);

        self::assertEquals(new ButtonPress(self::createContext()), $command);
    }

    public function testSetValueBecomesNumberCommand(): void
    {
        $command = self::createCommand(ComponentCommandAction::SetValue, ['value' => 1.5])->readExposedCommand(ExposedPlatform::Number);

        self::assertEquals(new NumberCommand(1.5, self::createContext()), $command);
    }

    public function testSelectOptionBecomesSelectCommand(): void
    {
        $command = self::createCommand(ComponentCommandAction::SelectOption, ['option' => 'comfort'])->readExposedCommand(ExposedPlatform::Select);

        self::assertEquals(new SelectCommand('comfort', self::createContext()), $command);
    }

    public function testSetValueBecomesTextCommand(): void
    {
        $command = self::createCommand(ComponentCommandAction::SetValue, ['value' => 'Good morning'])->readExposedCommand(ExposedPlatform::Text);

        self::assertEquals(new TextCommand('Good morning', self::createContext()), $command);
    }

    public function testSetValueBecomesTimeCommand(): void
    {
        $command = self::createCommand(ComponentCommandAction::SetValue, ['value' => '07:00:00'])->readExposedCommand(ExposedPlatform::Time);

        self::assertEquals(new TimeCommand(TimeOfDay::fromHourMinuteSecond(7, 0), self::createContext()), $command);
    }

    public function testSetValueBecomesDateCommand(): void
    {
        $command = self::createCommand(ComponentCommandAction::SetValue, ['value' => '2026-10-15'])->readExposedCommand(ExposedPlatform::Date);

        self::assertInstanceOf(DateCommand::class, $command);
        self::assertSame('2026-10-15', $command->getRequestedState()->value);
    }

    public function testSetValueBecomesDateTimeCommand(): void
    {
        $command = self::createCommand(ComponentCommandAction::SetValue, ['value' => '2026-10-09T18:30:00+00:00'])->readExposedCommand(ExposedPlatform::DateTime);

        self::assertInstanceOf(DateTimeCommand::class, $command);
        self::assertSame('2026-10-09T18:30:00+00:00', $command->getRequestedState()->value);
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('provideUnfitCommands')]
    public function testUnfitCommandIsNull(ComponentCommandAction $action, array $data, ExposedPlatform $platform): void
    {
        self::assertNull(self::createCommand($action, $data)->readExposedCommand($platform));
    }

    /** @return iterable<string, array{ComponentCommandAction, array<string, mixed>, ExposedPlatform}> */
    public static function provideUnfitCommands(): iterable
    {
        yield 'press on switch' => [ComponentCommandAction::Press, [], ExposedPlatform::Switch];
        yield 'turn on on button' => [ComponentCommandAction::TurnOn, [], ExposedPlatform::Button];
        yield 'set value on sensor' => [ComponentCommandAction::SetValue, ['value' => 1], ExposedPlatform::Sensor];
        yield 'number without value' => [ComponentCommandAction::SetValue, [], ExposedPlatform::Number];
        yield 'number with string value' => [ComponentCommandAction::SetValue, ['value' => '1'], ExposedPlatform::Number];
        yield 'number with bool value' => [ComponentCommandAction::SetValue, ['value' => true], ExposedPlatform::Number];
        yield 'select with value' => [ComponentCommandAction::SetValue, ['value' => 'eco'], ExposedPlatform::Select];
        yield 'select without option' => [ComponentCommandAction::SelectOption, [], ExposedPlatform::Select];
        yield 'text with number value' => [ComponentCommandAction::SetValue, ['value' => 5], ExposedPlatform::Text];
        yield 'time with bad value' => [ComponentCommandAction::SetValue, ['value' => '25:00:00'], ExposedPlatform::Time];
        yield 'date with bad value' => [ComponentCommandAction::SetValue, ['value' => '2026-13-01'], ExposedPlatform::Date];
        yield 'datetime without offset' => [ComponentCommandAction::SetValue, ['value' => '2026-10-09T18:30:00'], ExposedPlatform::DateTime];
    }

    /** @param array<string, mixed> $data */
    private static function createCommand(ComponentCommandAction $action, array $data = []): ComponentCommand
    {
        return new ComponentCommand('3f2b9c0e8d7a4f61', new AppId('climate'), new ExposedEntityKey('target_offset'), $action, $data, self::createContext());
    }

    private static function createContext(): EventContext
    {
        return new EventContext('context-1', null, 'user-1');
    }
}
