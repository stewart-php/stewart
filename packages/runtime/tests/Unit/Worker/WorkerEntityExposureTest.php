<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\CommandException;
use Stewart\Contracts\Exception\ExposureError;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\ButtonConfig;
use Stewart\Contracts\Exposure\Command\ButtonPress;
use Stewart\Contracts\Exposure\Command\ExposedCommand;
use Stewart\Contracts\Exposure\Command\NumberCommand;
use Stewart\Contracts\Exposure\Command\SelectCommand;
use Stewart\Contracts\Exposure\Command\SwitchAction;
use Stewart\Contracts\Exposure\Command\SwitchCommand;
use Stewart\Contracts\Exposure\Command\TimeCommand;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\NumberConfig;
use Stewart\Contracts\Exposure\SelectConfig;
use Stewart\Contracts\Exposure\SensorConfig;
use Stewart\Contracts\Exposure\SensorDeviceClass;
use Stewart\Contracts\Exposure\TextConfig;
use Stewart\Contracts\Schedule\TimeOfDay;
use Stewart\Contracts\State\EventContext;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Ipc\Message\ExposedCommandAnswered;
use Stewart\Runtime\Ipc\Message\ExposedEntityCommanded;
use Stewart\Runtime\Ipc\Message\ExposeEntityRequest;
use Stewart\Runtime\Ipc\Message\ReconfigureExposedEntityRequest;
use Stewart\Runtime\Ipc\Message\RemoveExposedEntityRequest;
use Stewart\Runtime\Ipc\Message\UpdateExposedEntityRequest;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Scope\ScopeLifecycle;
use Stewart\Runtime\Tests\Fixtures\Worker\ExposureBrokerStub;
use Stewart\Runtime\Tests\Fixtures\Worker\RecordingDispatchListener;
use Stewart\Runtime\Worker\CorrelationIdSequence;
use Stewart\Runtime\Worker\Exposure\ExposedCommandSettlements;
use Stewart\Runtime\Worker\Exposure\ExposedCommandStreams;
use Stewart\Runtime\Worker\Exposure\ExposedHandleRegistry;
use Stewart\Runtime\Worker\Exposure\ExposureRequester;
use Stewart\Runtime\Worker\Exposure\SettlingCommandStream;
use Stewart\Runtime\Worker\Exposure\WorkerExposedBinarySensor;
use Stewart\Runtime\Worker\Exposure\WorkerExposedButton;
use Stewart\Runtime\Worker\Exposure\WorkerExposedDate;
use Stewart\Runtime\Worker\Exposure\WorkerExposedDateTime;
use Stewart\Runtime\Worker\Exposure\WorkerExposedEntity;
use Stewart\Runtime\Worker\Exposure\WorkerExposedNumber;
use Stewart\Runtime\Worker\Exposure\WorkerExposedSelect;
use Stewart\Runtime\Worker\Exposure\WorkerExposedSensor;
use Stewart\Runtime\Worker\Exposure\WorkerExposedSwitch;
use Stewart\Runtime\Worker\Exposure\WorkerExposedText;
use Stewart\Runtime\Worker\Exposure\WorkerExposedTime;
use Stewart\Runtime\Worker\Message\ExposedEntityCommandedHandler;
use Stewart\Runtime\Worker\PendingRequests;
use Stewart\Runtime\Worker\WorkerEntityExposure;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;

#[CoversClass(WorkerEntityExposure::class)]
#[CoversClass(WorkerExposedEntity::class)]
#[CoversClass(WorkerExposedSensor::class)]
#[CoversClass(WorkerExposedBinarySensor::class)]
#[CoversClass(WorkerExposedSwitch::class)]
#[CoversClass(WorkerExposedButton::class)]
#[CoversClass(WorkerExposedNumber::class)]
#[CoversClass(WorkerExposedSelect::class)]
#[CoversClass(WorkerExposedText::class)]
#[CoversClass(WorkerExposedTime::class)]
#[CoversClass(WorkerExposedDate::class)]
#[CoversClass(WorkerExposedDateTime::class)]
#[CoversClass(ExposedCommandSettlements::class)]
#[CoversClass(ExposedCommandStreams::class)]
#[CoversClass(SettlingCommandStream::class)]
#[CoversClass(ExposedEntityCommandedHandler::class)]
final class WorkerEntityExposureTest extends TestCase
{
    use AssertsReason;

    private ExposureBrokerStub $broker;

    private ExposedHandleRegistry $handles;

    private WorkerEntityExposure $shared;

    private ExposedEntityCommandedHandler $commandHandler;

    protected function setUp(): void
    {
        $pending = new PendingRequests(new CorrelationIdSequence(new WorkerId(0)));
        $this->broker = new ExposureBrokerStub($pending);
        $this->handles = new ExposedHandleRegistry($this->broker, new NullLogger());
        $timers = new ManualTimers();
        $scopes = new ScopeLifecycle();
        $scopes->activateScope(ResourceScope::forApp(new AppId('climate')));
        $settlements = new ExposedCommandSettlements($this->broker, new NullLogger());
        $commandStreams = new ExposedCommandStreams(RecordingDispatchListener::createDispatcher('w0', 10, scopes: $scopes), $timers, $settlements);
        $this->commandHandler = new ExposedEntityCommandedHandler($this->handles, $commandStreams, $settlements);
        $this->shared = new WorkerEntityExposure(
            new ExposureRequester($this->broker, $pending, $timers, Duration::seconds(1)),
            $this->handles,
            $commandStreams,
            ResourceScope::shared(),
        );
    }

    public function testSnapshotSeedsTheHandle(): void
    {
        $this->broker->snapshot = new ExposedEntitySnapshot(new EntityId('sensor.climate_level'), new ExposedState(21.4), ['source' => 'hall'], true);

        $sensor = $this->createClimateExposure()->exposeSensor('level');

        self::assertSame('sensor.climate_level', $sensor->getEntityId()?->value);
        self::assertSame(21.4, $sensor->getValue());
        self::assertSame(['source' => 'hall'], $sensor->getAttributes());
    }

    public function testPendingHandleGetsEntityIdOnSync(): void
    {
        $sensor = $this->createClimateExposure()->exposeSensor('level');

        $this->handles->applySnapshot(
            ResourceScope::forApp(new AppId('climate')),
            new ExposedEntityKey('level'),
            new ExposedEntitySnapshot(new EntityId('sensor.climate_level'), new ExposedState(19), [], false),
        );

        self::assertSame('sensor.climate_level', $sensor->getEntityId()?->value);
        self::assertSame(19, $sensor->getValue());
        self::assertFalse($sensor->isAvailable());
    }

    public function testTimestampValueIsSentAsIsoString(): void
    {
        $sensor = $this->createClimateExposure()->exposeSensor('last_run', new SensorConfig(SensorDeviceClass::Timestamp));

        $sensor->setValue(new DateTimeImmutable('2026-10-09 07:30:00+02:00'), ['run' => 3]);

        $update = $this->broker->listSentOfType(UpdateExposedEntityRequest::class)[0];
        self::assertSame('2026-10-09T07:30:00+02:00', $update->change->state?->value);
        self::assertSame('2026-10-09T07:30:00+02:00', $sensor->getValue());
        self::assertSame(['run' => 3], $sensor->getAttributes());
    }

    public function testBinarySensorSendsBooleans(): void
    {
        $presence = $this->createClimateExposure()->exposeBinarySensor('anyone_home');

        $presence->setOn();
        $presence->markUnavailable();

        self::assertTrue($presence->getValue());
        self::assertFalse($presence->isAvailable());
        self::assertFalse($this->broker->listSentOfType(UpdateExposedEntityRequest::class)[1]->change->available);
    }

    public function testSwitchSendsBooleans(): void
    {
        $heater = $this->createClimateExposure()->exposeSwitch('heater');

        $heater->setOn();
        $heater->setOff();

        self::assertFalse($heater->getValue());
        self::assertSame([true, false], array_map(
            static fn(UpdateExposedEntityRequest $update) => $update->change->state?->value,
            $this->broker->listSentOfType(UpdateExposedEntityRequest::class),
        ));
    }

    public function testNumberSendsItsValue(): void
    {
        $offset = $this->createClimateExposure()->exposeNumber('target_offset', new NumberConfig(min: -3, max: 3));

        $offset->setValue(2);

        self::assertSame(2, $offset->getValue());
        self::assertSame(2, $this->broker->listSentOfType(UpdateExposedEntityRequest::class)[0]->change->state?->value);
        $this->assertThrowsReason(ExposureError::StateInvalid, static fn() => $offset->setValue(INF));
    }

    public function testSelectSendsItsOption(): void
    {
        $mode = $this->createClimateExposure()->exposeSelect('mode', new SelectConfig(['eco', 'comfort']));

        $mode->setOption('eco');
        $mode->setOption(null);

        self::assertNull($mode->getOption());
        self::assertSame(['eco', null], array_map(
            static fn(UpdateExposedEntityRequest $update) => $update->change->state?->value,
            $this->broker->listSentOfType(UpdateExposedEntityRequest::class),
        ));
    }

    public function testCalendarValuesAreSentAsIsoStrings(): void
    {
        $exposure = $this->createClimateExposure();
        $alarm = $exposure->exposeTime('alarm');
        $mowing = $exposure->exposeDate('next_mowing');
        $watered = $exposure->exposeDateTime('last_watered');

        $alarm->setValue(TimeOfDay::fromHourMinuteSecond(6, 45));
        $mowing->setValue(new DateTimeImmutable('2026-10-12 21:00:00+02:00'));
        $watered->setValue(new DateTimeImmutable('2026-10-09 07:15:00+02:00'));

        self::assertSame(['06:45:00', '2026-10-12', '2026-10-09T07:15:00+02:00'], array_map(
            static fn(UpdateExposedEntityRequest $update) => $update->change->state?->value,
            $this->broker->listSentOfType(UpdateExposedEntityRequest::class),
        ));
        self::assertSame('06:45:00', $alarm->getValue()?->format());
        self::assertSame('2026-10-12T00:00:00+00:00', $mowing->getValue()?->format(DATE_ATOM));
        self::assertSame('2026-10-09T07:15:00+02:00', $watered->getValue()?->format(DATE_ATOM));
    }

    public function testTextSendsItsValue(): void
    {
        $greeting = $this->createClimateExposure()->exposeText('greeting', new TextConfig(max: 40));

        $greeting->setValue('Hello');

        self::assertSame('Hello', $greeting->getValue());
    }

    public function testButtonIsExposedWithoutState(): void
    {
        $this->createClimateExposure()->exposeButton('boost', new ButtonConfig(name: 'Boost'));

        $request = $this->broker->listSentOfType(ExposeEntityRequest::class)[0];
        self::assertInstanceOf(ButtonConfig::class, $request->config);
        self::assertNull($request->change->state);
    }

    public function testSameKeyTwiceIsTaken(): void
    {
        $exposure = $this->createClimateExposure();
        $exposure->exposeSensor('level');

        $this->assertThrowsReason(ExposureError::KeyTaken, static fn() => $exposure->exposeBinarySensor('level'));
    }

    public function testRefusedExposureFreesItsKey(): void
    {
        $exposure = $this->createClimateExposure();
        $this->broker->failure = ExposureException::componentMissing(new ExposedEntityKey('level'));

        $this->assertThrowsReason(ExposureError::ComponentMissing, static fn() => $exposure->exposeSensor('level'));
        $this->broker->failure = null;

        self::assertSame('level', $exposure->exposeSensor('level')->getKey()->value);
    }

    public function testSharedScopeIsOutsideApp(): void
    {
        $this->assertThrowsReason(ExposureError::OutsideApp, fn() => $this->shared->exposeSensor('level'));
    }

    public function testRemovedHandleRefusesUpdates(): void
    {
        $exposure = $this->createClimateExposure();
        $sensor = $exposure->exposeSensor('level');

        $sensor->remove();

        self::assertCount(1, $this->broker->listSentOfType(RemoveExposedEntityRequest::class));
        $this->assertThrowsReason(ExposureError::Removed, static fn() => $sensor->setValue(3));
        self::assertSame('level', $exposure->exposeSensor('level')->getKey()->value);
    }

    public function testReleasedScopeRefusesUpdates(): void
    {
        $sensor = $this->createClimateExposure()->exposeSensor('level');

        $this->handles->releaseHandlesOf(ResourceScope::forApp(new AppId('climate')));

        $this->assertThrowsReason(ExposureError::Removed, static fn() => $sensor->setValue(3));
    }

    public function testUpdatedConfigIsSentAndKept(): void
    {
        $sensor = $this->createClimateExposure()->exposeSensor('level', new SensorConfig(unit: '%'));

        $sensor->updateConfig(new SensorConfig(unit: '%', name: 'Tank level'));

        $request = $this->broker->listSentOfType(ReconfigureExposedEntityRequest::class)[0];
        self::assertSame('Tank level', $request->config->name);
        self::assertSame('Tank level', $sensor->getConfig()->name);
    }

    public function testRefusedConfigKeepsPreviousOne(): void
    {
        $offset = $this->createClimateExposure()->exposeNumber('target_offset', new NumberConfig(min: -3, max: 3));
        $this->broker->failure = ExposureException::configInvalid('The unit does not fit.');

        $this->assertThrowsReason(ExposureError::ConfigInvalid, static fn() => $offset->updateConfig(new NumberConfig(min: 0, max: 10)));

        self::assertSame(3, $offset->getConfig()->max);
    }

    public function testReconfigureSnapshotDropsUnfitValue(): void
    {
        $mode = $this->createClimateExposure()->exposeSelect('mode', new SelectConfig(['eco', 'comfort']));
        $mode->setOption('comfort');
        $this->broker->snapshot = new ExposedEntitySnapshot(new EntityId('select.stewart_climate_mode'), new ExposedState(null), [], true);

        $mode->updateConfig(new SelectConfig(['eco']));

        self::assertNull($mode->getOption());
        self::assertSame('select.stewart_climate_mode', $mode->getEntityId()?->value);
    }

    public function testRemovedHandleRefusesReconfigure(): void
    {
        $button = $this->createClimateExposure()->exposeButton('boost');
        $button->remove();

        $this->assertThrowsReason(ExposureError::Removed, static fn() => $button->updateConfig(new ButtonConfig(name: 'Boost')));
        self::assertSame([], $this->broker->listSentOfType(ReconfigureExposedEntityRequest::class));
    }

    public function testAcceptedCommandSetsSwitchValue(): void
    {
        $heater = $this->createClimateExposure()->exposeSwitch('heater');
        $received = [];
        $heater->watchCommands()->subscribe(static function (SwitchCommand $command) use (&$received): void {
            $received[] = $command;
        });

        $this->deliverCommand('heater', self::createSwitchCommand(SwitchAction::TurnOn));

        self::assertCount(1, $received);
        self::assertTrue($this->requireOnlyAnswer()->accepted);
        self::assertTrue($heater->getValue());
    }

    public function testRejectedCommandKeepsReasonAndValue(): void
    {
        $heater = $this->createClimateExposure()->exposeSwitch('heater');
        $heater->watchCommands()->subscribe(static function (): void {
            throw CommandException::rejected('Alarm is armed.');
        });

        $this->deliverCommand('heater', self::createSwitchCommand(SwitchAction::TurnOn));

        $answer = $this->requireOnlyAnswer();
        self::assertFalse($answer->accepted);
        self::assertSame('Alarm is armed.', $answer->rejection);
        self::assertNull($heater->getValue());
    }

    public function testAcceptedCommandSetsNumberValue(): void
    {
        $offset = $this->createClimateExposure()->exposeNumber('target_offset', new NumberConfig(min: -3, max: 3, step: 0.5));

        $this->deliverCommand('target_offset', new NumberCommand(1.5, new EventContext('context-1')));

        self::assertTrue($this->requireOnlyAnswer()->accepted);
        self::assertSame(1.5, $offset->getValue());
    }

    public function testAcceptedCommandSetsSelectOption(): void
    {
        $mode = $this->createClimateExposure()->exposeSelect('mode', new SelectConfig(['eco', 'comfort']));
        $mode->watchCommands()->subscribe(static function (): void {});

        $this->deliverCommand('mode', new SelectCommand('comfort', new EventContext('context-1')));

        self::assertTrue($this->requireOnlyAnswer()->accepted);
        self::assertSame('comfort', $mode->getOption());
    }

    public function testAcceptedCommandSetsTimeValue(): void
    {
        $alarm = $this->createClimateExposure()->exposeTime('alarm');

        $this->deliverCommand('alarm', new TimeCommand(TimeOfDay::fromHourMinuteSecond(7, 0), new EventContext('context-1')));

        self::assertTrue($this->requireOnlyAnswer()->accepted);
        self::assertSame('07:00:00', $alarm->getValue()?->format());
    }

    public function testCommandWithoutSubscriberIsAccepted(): void
    {
        $this->createClimateExposure()->exposeButton('boost');

        $this->deliverCommand('boost', new ButtonPress(new EventContext('context-1')));

        self::assertTrue($this->requireOnlyAnswer()->accepted);
    }

    public function testFilteredCommandIsNotAnswered(): void
    {
        $heater = $this->createClimateExposure()->exposeSwitch('heater');
        $heater->watchCommands()->filter(static fn(SwitchCommand $command): bool => !$command->isTurnOn())->subscribe(static function (): void {});

        $this->deliverCommand('heater', self::createSwitchCommand(SwitchAction::TurnOn));

        self::assertSame([], $this->broker->listSentOfType(ExposedCommandAnswered::class));
    }

    public function testFailingHandlerRejectsCommand(): void
    {
        $heater = $this->createClimateExposure()->exposeSwitch('heater');
        $heater->watchCommands()->subscribe(static function (): void {
            throw new RuntimeException('boom');
        });

        $this->deliverCommand('heater', self::createSwitchCommand(SwitchAction::TurnOff));

        self::assertSame('The app failed to handle the command.', $this->requireOnlyAnswer()->rejection);
    }

    public function testFirstSubscriberAnswers(): void
    {
        $heater = $this->createClimateExposure()->exposeSwitch('heater');
        $heater->watchCommands()->subscribe(static function (): void {});
        $heater->watchCommands()->subscribe(static function (): void {
            throw CommandException::rejected('Too late.');
        });

        $this->deliverCommand('heater', self::createSwitchCommand(SwitchAction::TurnOn));

        self::assertTrue($this->requireOnlyAnswer()->accepted);
    }

    public function testCommandForUnknownKeyIsRefused(): void
    {
        $this->deliverCommand('heater', self::createSwitchCommand(SwitchAction::TurnOn));

        self::assertSame('Entity heater is no longer exposed.', $this->requireOnlyAnswer()->rejection);
    }

    private function deliverCommand(string $key, ExposedCommand $command): void
    {
        $this->commandHandler->handle(new ExposedEntityCommanded('3f2b9c0e8d7a4f61', ResourceScope::forApp(new AppId('climate')), new ExposedEntityKey($key), $command));
        EventLoopTicks::settle();
    }

    private function requireOnlyAnswer(): ExposedCommandAnswered
    {
        $answers = $this->broker->listSentOfType(ExposedCommandAnswered::class);
        self::assertCount(1, $answers);

        return $answers[0];
    }

    private static function createSwitchCommand(SwitchAction $action): SwitchCommand
    {
        return new SwitchCommand($action, new EventContext('context-1'));
    }

    private function createClimateExposure(): WorkerEntityExposure
    {
        return $this->shared->forApp(new AppId('climate'));
    }
}
