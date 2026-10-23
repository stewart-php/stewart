<?php

declare(strict_types=1);

namespace Stewart\Testing\Tests\Unit\App;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\App\AppArgumentResolver;
use Stewart\Testing\App\AppHarness;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Exception\HarnessError;
use Stewart\Testing\Exception\HarnessException;
use Stewart\Testing\Tests\Fixtures\App\DoorAlarmApp;
use Stewart\Testing\Tests\Fixtures\App\HallLightApp;
use Stewart\Testing\Tests\Fixtures\App\LegacyDisposeApp;
use Stewart\Testing\Tests\Fixtures\App\Notifier;
use Stewart\Testing\Tests\Fixtures\App\NotifyingApp;
use Stewart\Testing\Tests\Fixtures\App\PorchStatsApp;
use Stewart\Testing\Tests\Fixtures\App\RecordingNotifier;
use Stewart\Testing\Tests\Fixtures\App\UnmarkedApp;
use Stewart\Testing\Tests\Fixtures\App\WaitingInitializeApp;

#[CoversClass(AppHarness::class)]
#[CoversClass(AppArgumentResolver::class)]
#[CoversClass(HarnessException::class)]
#[CoversClass(HarnessError::class)]
final class AppHarnessTest extends TestCase
{
    use AssertsReason;

    private AppHarness $harness;

    protected function setUp(): void
    {
        $this->harness = new AppHarness();
    }

    public function testAppGetsItsOptionsAndTheFakes(): void
    {
        $this->harness->ha->seedState('binary_sensor.hall_motion', 'off');
        $this->harness->startApp(HallLightApp::class, ['motionSensor' => 'binary_sensor.hall_motion']);

        $this->harness->ha->pushState('binary_sensor.hall_motion', 'on');

        self::assertSame('light.turn_on', $this->harness->ha->getLastCall()->getActionName());
        self::assertSame(['Motion in the hall'], $this->harness->logger->listMessagesAt('info'));
        self::assertSame(1, $this->harness->stores->openAppStore(new AppId('hall-light'))->getInt('motions'));
    }

    public function testScheduledWorkRunsAsTimeAdvances(): void
    {
        $this->harness->ha->seedState('binary_sensor.hall_motion', 'off');
        $this->harness->startApp(HallLightApp::class, ['motionSensor' => 'binary_sensor.hall_motion', 'offAfterMinutes' => 2]);
        $this->harness->ha->pushState('binary_sensor.hall_motion', 'on');

        $this->harness->advanceTime(Duration::seconds(119));
        self::assertSame('light.turn_on', $this->harness->ha->getLastCall()->getActionName());

        $this->harness->advanceTime(Duration::seconds(1));
        self::assertSame('light.turn_off', $this->harness->ha->getLastCall()->getActionName());
    }

    public function testWaitInAHandlerEndsWhenTheStateArrives(): void
    {
        $this->harness->ha->seedState('binary_sensor.front_door', 'off');
        $this->harness->startApp(DoorAlarmApp::class);

        $this->harness->ha->pushState('binary_sensor.front_door', 'on');
        $this->harness->advanceTime(Duration::minutes(1));
        $this->harness->ha->pushState('binary_sensor.front_door', 'off');
        $this->harness->advanceTime(Duration::minutes(10));

        self::assertCount(0, $this->harness->ha->calls);
    }

    public function testWaitInAHandlerTimesOutInVirtualTime(): void
    {
        $this->harness->ha->seedState('binary_sensor.front_door', 'off');
        $this->harness->startApp(DoorAlarmApp::class);

        $this->harness->ha->pushState('binary_sensor.front_door', 'on');
        $this->harness->advanceTime(Duration::minutes(5));

        self::assertSame('notify.notify', $this->harness->ha->getLastCall()->getActionName());
    }

    public function testStoresAndGeneratedRootsAreResolved(): void
    {
        $this->harness->stores->openAppStore(new AppId('hall-light'))->set('motions', 3);

        $app = $this->harness->startApp(PorchStatsApp::class);

        self::assertSame(3, $this->harness->stores->openAppStore(new AppId('porch-stats'))->getInt('hall_motions_seen'));
        self::assertTrue($this->harness->stores->openGlobalStore()->getBool('porch_ready'));
        self::assertSame($this->harness->ha, $app->entities->ha);
    }

    public function testProvidedServicesReachTheApp(): void
    {
        $notifier = new RecordingNotifier();
        $this->harness->provideService(Notifier::class, $notifier);

        $this->harness->startApp(NotifyingApp::class);

        self::assertSame(['started'], $notifier->messages);
    }

    public function testEventsWaitUntilInitializeReturns(): void
    {
        $app = $this->harness->startApp(WaitingInitializeApp::class);

        $this->harness->ha->pushState('sensor.outside_temperature', '12.5');
        $this->harness->ha->pushState('input_boolean.ready', 'on');
        self::assertSame([], $app->seen, 'Like the worker, the harness delivers nothing while initialize() runs.');
        self::assertNull($app->ready);

        $this->harness->advanceTime(Duration::seconds(10));

        self::assertFalse($app->ready, 'The pushed state was held back, so the wait timed out.');
        self::assertSame(['12.5'], $app->seen);
    }

    public function testStoppingEndsPendingWaitsAndDisposes(): void
    {
        $this->harness->ha->seedState('binary_sensor.front_door', 'off');
        $app = $this->harness->startApp(DoorAlarmApp::class);
        $this->harness->ha->pushState('binary_sensor.front_door', 'on');

        $this->harness->stopApp();
        $this->harness->advanceTime(Duration::minutes(10));
        $this->harness->ha->pushState('binary_sensor.front_door', 'on');

        self::assertTrue($app->disposed);
        self::assertCount(0, $this->harness->ha->calls);
    }

    public function testStopAppStillDisposesWithoutDisposable(): void
    {
        $app = $this->harness->startApp(LegacyDisposeApp::class);

        $this->harness->stopApp();

        self::assertTrue($app->disposed);
    }

    public function testUnknownOptionIsRefused(): void
    {
        $this->assertThrowsReason(HarnessError::OptionUnknown, fn() => $this->harness->startApp(HallLightApp::class, ['motionSensor' => 'binary_sensor.hall_motion', 'motion' => 'x']));
    }

    public function testMissingOptionIsRefused(): void
    {
        $this->assertThrowsReason(HarnessError::OptionMissing, fn() => $this->harness->startApp(HallLightApp::class));
    }

    public function testServiceWithoutAFakeIsRefused(): void
    {
        $this->assertThrowsReason(HarnessError::ParameterUnresolvable, fn() => $this->harness->startApp(NotifyingApp::class));
    }

    public function testClassWithoutAutomationIsRefused(): void
    {
        $this->assertThrowsReason(HarnessError::AutomationMissing, fn() => $this->harness->startApp(UnmarkedApp::class));
    }

    public function testHarnessRunsOneApp(): void
    {
        $this->harness->startApp(DoorAlarmApp::class);

        $this->assertThrowsReason(HarnessError::AlreadyStarted, fn() => $this->harness->startApp(DoorAlarmApp::class));
        $this->assertThrowsReason(HarnessError::AlreadyStarted, fn() => $this->harness->provideService(Notifier::class, new RecordingNotifier()));
    }

    public function testStoppingBeforeStartingIsRefused(): void
    {
        $this->assertThrowsReason(HarnessError::NotStarted, fn() => $this->harness->stopApp());
    }
}
