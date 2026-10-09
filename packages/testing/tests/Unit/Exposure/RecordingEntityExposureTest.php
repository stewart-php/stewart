<?php

declare(strict_types=1);

namespace Stewart\Testing\Tests\Unit\Exposure;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\CommandError;
use Stewart\Contracts\Exception\CommandException;
use Stewart\Contracts\Exception\ExposureError;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\Command\SwitchAction;
use Stewart\Contracts\Exposure\Command\SwitchCommand;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\Exposure\SensorConfig;
use Stewart\Contracts\State\EventContext;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Exposure\RecordingEntityExposure;
use Stewart\Testing\Exposure\RecordingExposedBinarySensor;
use Stewart\Testing\Exposure\RecordingExposedButton;
use Stewart\Testing\Exposure\RecordingExposedEntity;
use Stewart\Testing\Exposure\RecordingExposedSensor;
use Stewart\Testing\Exposure\RecordingExposedSwitch;

#[CoversClass(RecordingEntityExposure::class)]
#[CoversClass(RecordingExposedEntity::class)]
#[CoversClass(RecordingExposedSensor::class)]
#[CoversClass(RecordingExposedBinarySensor::class)]
#[CoversClass(RecordingExposedSwitch::class)]
#[CoversClass(RecordingExposedButton::class)]
final class RecordingEntityExposureTest extends TestCase
{
    use AssertsReason;

    public function testValuesAreRecordedInOrder(): void
    {
        $exposure = new RecordingEntityExposure();
        $sensor = $exposure->exposeSensor('level', new SensorConfig(unit: '%'));

        $sensor->setValue(40);
        $sensor->setValue(42, ['source' => 'probe']);

        $config = $exposure->exposures->getFirst()?->config;

        self::assertSame([40, 42], $exposure->requireSensor('level')->changes->mapToList(static fn(ExposedStateChange $change) => $change->state?->value));
        self::assertSame(42, $sensor->getValue());
        self::assertInstanceOf(SensorConfig::class, $config);
        self::assertSame('%', $config->unit);
    }

    public function testSeededSnapshotActsAsRestoredState(): void
    {
        $exposure = new RecordingEntityExposure();
        $exposure->seedSnapshot('anyone_home', new ExposedEntitySnapshot(new EntityId('binary_sensor.anyone_home'), new ExposedState(true), [], true));

        $presence = $exposure->exposeBinarySensor('anyone_home');

        self::assertTrue($presence->getValue());
        self::assertSame('binary_sensor.anyone_home', $presence->getEntityId()?->value);
    }

    public function testSwitchRecordsItsValues(): void
    {
        $exposure = new RecordingEntityExposure();
        $heater = $exposure->exposeSwitch('heater');

        $heater->setOn();
        $heater->setOff();

        self::assertSame([true, false], $exposure->requireSwitch('heater')->changes->mapToList(static fn(ExposedStateChange $change) => $change->state?->value));
        self::assertFalse($heater->getValue());
    }

    public function testAcceptedCommandSetsSwitchValue(): void
    {
        $exposure = new RecordingEntityExposure();
        $exposure->exposeSwitch('heater');
        $heater = $exposure->requireSwitch('heater');
        $received = [];
        $heater->watchCommands()->subscribe(static function (SwitchCommand $command) use (&$received): void {
            $received[] = $command;
        });

        $heater->pushCommand(new SwitchCommand(SwitchAction::TurnOn, new EventContext('context-1')));

        self::assertCount(1, $received);
        self::assertTrue($heater->getValue());
    }

    public function testRejectedCommandReachesTheTest(): void
    {
        $exposure = new RecordingEntityExposure();
        $exposure->exposeSwitch('heater');
        $heater = $exposure->requireSwitch('heater');
        $heater->watchCommands()->subscribe(static function (): void {
            throw CommandException::rejected('Alarm is armed.');
        });

        $this->assertThrowsReason(CommandError::Rejected, static fn() => $heater->pushCommand(new SwitchCommand(SwitchAction::TurnOn, new EventContext('context-1'))));
        self::assertNull($heater->getValue());
    }

    public function testButtonIsRecorded(): void
    {
        $exposure = new RecordingEntityExposure();

        $exposure->exposeButton('boost')->markUnavailable();

        self::assertFalse($exposure->requireButton('boost')->isAvailable());
    }

    public function testSameKeyTwiceIsTaken(): void
    {
        $exposure = new RecordingEntityExposure();
        $exposure->exposeSensor('level');

        $this->assertThrowsReason(ExposureError::KeyTaken, static fn() => $exposure->exposeSensor('level'));
    }

    public function testRemovedHandleFreesItsKey(): void
    {
        $exposure = new RecordingEntityExposure();
        $sensor = $exposure->exposeSensor('level');

        $sensor->remove();

        $this->assertThrowsReason(ExposureError::Removed, static fn() => $sensor->setValue(1));
        self::assertNotSame($sensor, $exposure->exposeSensor('level'));
    }

    public function testRefusalIsThrown(): void
    {
        $exposure = new RecordingEntityExposure();
        $exposure->refuseExposures(ExposureException::componentMissing(new ExposedEntityKey('level')));

        $this->assertThrowsReason(ExposureError::ComponentMissing, static fn() => $exposure->exposeSensor('level'));
        self::assertTrue($exposure->exposures->isEmpty());
    }
}
