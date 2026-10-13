<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Ipc;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exposure\Action\ActionCall;
use Stewart\Contracts\Exposure\Action\ActionConfig;
use Stewart\Contracts\Exposure\Action\ActionField;
use Stewart\Contracts\Exposure\Action\ActionFieldType;
use Stewart\Contracts\Exposure\BinarySensorConfig;
use Stewart\Contracts\Exposure\BinarySensorDeviceClass;
use Stewart\Contracts\Exposure\ButtonConfig;
use Stewart\Contracts\Exposure\ButtonDeviceClass;
use Stewart\Contracts\Exposure\Command\ButtonPress;
use Stewart\Contracts\Exposure\Command\DateCommand;
use Stewart\Contracts\Exposure\Command\DateTimeCommand;
use Stewart\Contracts\Exposure\Command\ExposedCommand;
use Stewart\Contracts\Exposure\Command\NumberCommand;
use Stewart\Contracts\Exposure\Command\SelectCommand;
use Stewart\Contracts\Exposure\Command\SwitchAction;
use Stewart\Contracts\Exposure\Command\SwitchCommand;
use Stewart\Contracts\Exposure\Command\TextCommand;
use Stewart\Contracts\Exposure\Command\TimeCommand;
use Stewart\Contracts\Exposure\DateConfig;
use Stewart\Contracts\Exposure\DateTimeConfig;
use Stewart\Contracts\Exposure\EntityCategory;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposureConfig;
use Stewart\Contracts\Exposure\NumberConfig;
use Stewart\Contracts\Exposure\NumberDeviceClass;
use Stewart\Contracts\Exposure\NumberMode;
use Stewart\Contracts\Exposure\SelectConfig;
use Stewart\Contracts\Exposure\SensorConfig;
use Stewart\Contracts\Exposure\SensorDeviceClass;
use Stewart\Contracts\Exposure\SwitchConfig;
use Stewart\Contracts\Exposure\SwitchDeviceClass;
use Stewart\Contracts\Exposure\TextConfig;
use Stewart\Contracts\Exposure\TextMode;
use Stewart\Contracts\Exposure\TimeConfig;
use Stewart\Contracts\Schedule\TimeOfDay;
use Stewart\Contracts\State\EventContext;
use Stewart\Runtime\Ipc\Message\ExposedEntityCommanded;
use Stewart\Runtime\Ipc\Message\ReconfigureExposedEntityRequest;
use Stewart\Runtime\Ipc\Wire\DateTimeImmutableConverter;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Stewart\Runtime\Ipc\Wire\TimeOfDayConverter;
use Stewart\Runtime\Json\WireMapper;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Support\Exception\JsonShapeError;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(TimeOfDayConverter::class)]
#[CoversClass(DateTimeImmutableConverter::class)]
final class ExposureWireTest extends TestCase
{
    use AssertsReason;

    private WireMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = IpcCodec::createIpcWireMapper();
    }

    /** @return iterable<string, array{ExposureConfig}> */
    public static function provideConfigs(): iterable
    {
        yield 'sensor' => [new SensorConfig(SensorDeviceClass::Enum, options: ['low', 'high'])];
        yield 'binary sensor' => [new BinarySensorConfig(BinarySensorDeviceClass::Window, name: 'Window', entityCategory: EntityCategory::Diagnostic, enabledByDefault: false)];
        yield 'switch' => [new SwitchConfig(SwitchDeviceClass::Outlet, name: 'Heater', icon: 'mdi:radiator')];
        yield 'button' => [new ButtonConfig(ButtonDeviceClass::Restart, entityCategory: EntityCategory::Config)];
        yield 'number' => [new NumberConfig(-3, 3, 0.5, NumberMode::Slider, NumberDeviceClass::Temperature, '°C', name: 'Target offset')];
        yield 'select' => [new SelectConfig(['eco', 'comfort'], icon: 'mdi:radiator')];
        yield 'text' => [new TextConfig(1, 40, '^[a-z]+$', TextMode::Password, name: 'Code')];
        yield 'time' => [new TimeConfig('Alarm')];
        yield 'date' => [new DateConfig(icon: 'mdi:mower')];
        yield 'datetime' => [new DateTimeConfig(enabledByDefault: false)];
        yield 'action' => [new ActionConfig('Boost', 'Heats a room.', [
            new ActionField('room', ActionFieldType::Select, required: true, options: ['office', 'hall']),
            new ActionField('minutes', ActionFieldType::Number, description: 'How long.'),
        ])];
    }

    #[DataProvider('provideConfigs')]
    public function testEveryConfigSurvivesTheWire(ExposureConfig $config): void
    {
        $sent = new ReconfigureExposedEntityRequest(new CorrelationId('w0:1'), ResourceScope::shared(), new ExposedEntityKey('key'), $config);

        self::assertEquals($sent, $this->sendThroughTheWire($sent));
    }

    /** @return iterable<string, array{ExposedCommand}> */
    public static function provideCommands(): iterable
    {
        $context = new EventContext('context-1', 'parent-1', 'user-1');

        yield 'switch' => [new SwitchCommand(SwitchAction::TurnOff, $context)];
        yield 'button' => [new ButtonPress($context)];
        yield 'number' => [new NumberCommand(2.5, $context)];
        yield 'select' => [new SelectCommand('comfort', $context)];
        yield 'text' => [new TextCommand('Good morning', $context)];
        yield 'time' => [new TimeCommand(TimeOfDay::fromHourMinuteSecond(7, 0), $context)];
        yield 'date' => [new DateCommand(new DateTimeImmutable('2026-10-15', new DateTimeZone('UTC')), $context)];
        yield 'datetime' => [new DateTimeCommand(new DateTimeImmutable('2026-10-09T18:30:00+02:00'), $context)];
        yield 'action' => [new ActionCall(['room' => 'office', 'minutes' => 45.5, 'quiet' => true], $context)];
    }

    #[DataProvider('provideCommands')]
    public function testEveryCommandSurvivesTheWire(ExposedCommand $command): void
    {
        $sent = new ExposedEntityCommanded('command-1', ResourceScope::shared(), new ExposedEntityKey('key'), $command);

        self::assertEquals($sent, $this->sendThroughTheWire($sent));
    }

    public function testPlatformNamesTheVariant(): void
    {
        $data = $this->mapper->normalizeObject(new ExposedEntityCommanded('command-1', ResourceScope::shared(), new ExposedEntityKey('key'), new ButtonPress(new EventContext('context-1'))));

        self::assertIsArray($data);
        self::assertSame(['platform' => 'button', 'context' => ['id' => 'context-1', 'parent_id' => null, 'user_id' => null]], $data['command']);
    }

    /** @return iterable<string, array{array<string, mixed>, JsonShapeError}> */
    public static function provideMalformedCommands(): iterable
    {
        $context = ['id' => 'context-1', 'parent_id' => null, 'user_id' => null];

        yield 'platform without commands' => [['platform' => 'sensor', 'context' => $context], JsonShapeError::UnexpectedValue];
        yield 'number as text' => [['platform' => 'number', 'value' => '2', 'context' => $context], JsonShapeError::WrongType];
        yield 'bad date' => [['platform' => 'date', 'value' => '2026-13-01T00:00:00+00:00', 'context' => $context], JsonShapeError::UnexpectedValue];
        yield 'bad time' => [['platform' => 'time', 'value' => '25:00:00', 'context' => $context], JsonShapeError::UnexpectedValue];
        yield 'unknown switch action' => [['platform' => 'switch', 'action' => 'toggle', 'context' => $context], JsonShapeError::UnexpectedValue];
    }

    /** @param array<string, mixed> $command */
    #[DataProvider('provideMalformedCommands')]
    public function testMalformedCommandIsRejected(array $command, JsonShapeError $reason): void
    {
        $this->assertThrowsReason($reason, fn() => $this->mapper->decodeObject(
            ExposedEntityCommanded::class,
            ['command_id' => 'command-1', 'scope' => '@shared', 'key' => 'key', 'command' => $command],
        ));
    }

    public function testConfigItsConstructorRefusesIsRejected(): void
    {
        $data = $this->mapper->normalizeObject(new ReconfigureExposedEntityRequest(new CorrelationId('w0:1'), ResourceScope::shared(), new ExposedEntityKey('key'), new SwitchConfig()));
        self::assertIsArray($data);
        self::assertIsArray($data['config']);
        $data['config']['icon'] = 'thermometer';

        $this->assertThrowsReason(JsonShapeError::ObjectRefused, fn() => $this->mapper->decodeObject(ReconfigureExposedEntityRequest::class, $data));
    }

    private function sendThroughTheWire(object $sent): object
    {
        $data = json_decode($this->mapper->encodeObject($sent), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $this->mapper->decodeObject($sent::class, $data);
    }
}
