<?php

declare(strict_types=1);

namespace Stewart\Codegen\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Stewart\Codegen\Tests\Fixtures\Expected\Entities;
use Stewart\Codegen\Tests\Fixtures\Expected\LightState;
use Stewart\Codegen\Tests\Fixtures\Expected\Manifest;
use Stewart\Codegen\Tests\Fixtures\Expected\Services;
use Stewart\Contracts\Generated\GeneratedFormat;
use Stewart\Contracts\Service\ServiceTarget;
use Stewart\Contracts\State\StateChange;
use Stewart\Testing\HaContext\RecordingHaContext;

#[CoversNothing]
final class GeneratedCodeBehaviorTest extends TestCase
{
    public function testHandleServiceTargetsOwnEntity(): void
    {
        $ha = new RecordingHaContext();

        new Entities($ha)->light->hall->turnOn(brightnessPct: 60, flash: 'short');

        self::assertSame('light.turn_on', $ha->getLastCall()->getServiceName());
        self::assertSame(['brightness_pct' => 60, 'flash' => 'short'], $ha->getLastCall()->data);
        self::assertSame(['light.hall'], $ha->getLastCall()->listTargetedEntityIds());
    }

    public function testUnsetFieldsNeverReachHomeAssistant(): void
    {
        $ha = new RecordingHaContext();

        new Entities($ha)->light->hall->turnOff();

        self::assertSame([], $ha->getLastCall()->data);
    }

    public function testServiceNamedStateKeepsItsName(): void
    {
        $ha = new RecordingHaContext();

        new Entities($ha)->light->hall->state('on');

        self::assertSame('light.state', $ha->getLastCall()->getServiceName());
    }

    public function testTypedStateAccessorsReadTheAttributeBag(): void
    {
        $ha = new RecordingHaContext()->seedState('light.hall', 'on', [
            'brightness' => 200,
            'color_temp_kelvin' => 3700,
            'is_hue_group' => false,
            'supported_color_modes' => ['color_temp'],
            'friendly_name' => 'Hall',
        ]);

        $hall = new Entities($ha)->light->hall;
        $state = $hall->requireState();

        self::assertTrue($state->isOn());
        self::assertFalse($state->isOff());
        self::assertSame(200.0, $state->getBrightness());
        self::assertSame(3700.0, $state->getColorTempKelvin());
        self::assertSame(['color_temp'], $state->getSupportedColorModes());
        self::assertSame('Hall', $state->getFriendlyName());
        self::assertSame('light.hall', (string) $hall->id);
        self::assertSame($state->raw->attributes, $hall->listAttributes());
    }

    public function testStateIsNullForUnseenEntity(): void
    {
        self::assertNull(new Entities(new RecordingHaContext())->light->hall->getState());
        self::assertNull(LightState::fromNullableState(null));
    }

    public function testNumericDomainReadsNumber(): void
    {
        $ha = new RecordingHaContext()->seedState('sensor.hall_temperature', '21.4', ['battery' => 80]);
        $state = new Entities($ha)->sensor->hallTemperature->getState();

        self::assertNotNull($state);
        self::assertSame(21.4, $state->getStateAsFloat());
        self::assertSame(80.0, $state->getBattery());
    }

    public function testRenamedEntityKeepsId(): void
    {
        self::assertSame('sensor.hall_humidity', (string) new Entities(new RecordingHaContext())->sensor->roomHumidity->id);
    }

    public function testHandleDeliversOwnStateChanges(): void
    {
        $ha = new RecordingHaContext();
        $seen = [];

        new Entities($ha)->binarySensor->hallMotion->watchStateChanges()
            ->whenChangedTo('on')
            ->subscribe(static function (StateChange $change) use (&$seen): void {
                $seen[] = $change->entityId->value;
            });

        $ha->pushState('binary_sensor.hall_motion', 'off');
        $ha->pushState('light.hall', 'on');
        $ha->pushState('binary_sensor.hall_motion', 'on');

        self::assertSame(['binary_sensor.hall_motion'], $seen);
    }

    public function testRootServiceTakesTargetOrEntity(): void
    {
        $ha = new RecordingHaContext();
        $services = new Services($ha);

        $services->light->turnOn(new Entities($ha)->light->hall, brightnessPct: 40);
        self::assertSame(['light.hall'], $ha->getLastCall()->listTargetedEntityIds());

        $services->light->turnOn(ServiceTarget::forAreas('kitchen'));
        self::assertSame(['kitchen'], $ha->getLastCall()->target?->areaIds);
    }

    public function testUntargetedServiceSendsNoTarget(): void
    {
        $ha = new RecordingHaContext();

        new Services($ha)->notify->mobileAppPhone('Hall light on', title: 'Stewart');

        self::assertSame('notify.mobile_app_phone', $ha->getLastCall()->getServiceName());
        self::assertNull($ha->getLastCall()->target);
        self::assertSame(['message' => 'Hall light on', 'title' => 'Stewart'], $ha->getLastCall()->data);
    }

    public function testUntargetedServiceTakesFieldNamedTarget(): void
    {
        $ha = new RecordingHaContext();

        new Services($ha)->notify->mobileAppPhone('Hall light on', target: ['phone']);

        self::assertSame(['message' => 'Hall light on', 'target' => ['phone']], $ha->getLastCall()->data);
        self::assertNull($ha->getLastCall()->target);
    }

    public function testConstantFieldTakesItsValue(): void
    {
        $ha = new RecordingHaContext();

        new Entities($ha)->light->hall->turnOn(white: true);

        self::assertSame(['white' => true], $ha->getLastCall()->data);
    }

    public function testIdleLightStillHasTypedAccessors(): void
    {
        $state = new Entities(new RecordingHaContext()->seedState('light.porch2', 'off', []))->light->porch2->requireState();

        self::assertNull($state->getBrightness());
        self::assertNull($state->getEffect());
    }

    public function testRespondingServiceAsksForResponse(): void
    {
        $ha = new RecordingHaContext()->stubServiceAnswer('weather', 'get_forecasts', ['forecast' => []]);

        $result = new Services($ha)->weather->getForecasts(ServiceTarget::forEntities('weather.home'), 'daily');

        self::assertTrue($ha->getLastCall()->returnsResponse);
        self::assertSame(['forecast' => []], $result->response);
    }

    public function testManifestCarriesCurrentFormat(): void
    {
        self::assertSame(GeneratedFormat::VERSION, Manifest::FORMAT_VERSION);
    }

    public function testManifestRecordsGeneration(): void
    {
        self::assertContains('light.hall', Manifest::listEntityIds());
        self::assertSame(['light.attic', 'light.cellar', 'light.debug_strip'], Manifest::listIgnoredEntityIds());
    }
}
