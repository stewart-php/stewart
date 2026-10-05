<?php

declare(strict_types=1);

namespace Stewart\Codegen\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Stewart\Codegen\Tests\Fixtures\Expected\Entities;
use Stewart\Codegen\Tests\Fixtures\Expected\LightEntity;
use Stewart\Codegen\Tests\Fixtures\Expected\LightState;
use Stewart\Codegen\Tests\Fixtures\Expected\Manifest;
use Stewart\Codegen\Tests\Fixtures\Expected\Services;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\IdentifierError;
use Stewart\Contracts\Generated\GeneratedFormat;
use Stewart\Contracts\History\HistoryQuery;
use Stewart\Contracts\Service\ServiceTarget;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\HaContext\RecordingHaContext;

#[CoversNothing]
final class GeneratedCodeBehaviorTest extends TestCase
{
    use AssertsReason;

    public function testHandleServiceTargetsOwnEntity(): void
    {
        $ha = new RecordingHaContext();

        new Entities($ha)->light->getEntity('light.hall')->turnOn(brightnessPct: 60, flash: 'short');

        self::assertSame('light.turn_on', $ha->getLastCall()->getServiceName());
        self::assertSame(['brightness_pct' => 60, 'flash' => 'short'], $ha->getLastCall()->data);
        self::assertSame(['light.hall'], $ha->getLastCall()->listTargetedEntityIds());
    }

    public function testServiceMethodsReturnCallContext(): void
    {
        $ha = new RecordingHaContext();

        $fromHandle = new Entities($ha)->light->getEntity('light.hall')->turnOn();
        $fromServices = new Services($ha)->light->turnOff(ServiceTarget::forAreas('kitchen'));

        self::assertSame($ha->calls->getFirst()?->context, $fromHandle);
        self::assertSame($ha->getLastCall()->context, $fromServices);
    }

    public function testUnsetFieldsNeverReachHomeAssistant(): void
    {
        $ha = new RecordingHaContext();

        new Entities($ha)->light->getEntity('light.hall')->turnOff();

        self::assertSame([], $ha->getLastCall()->data);
    }

    public function testServiceNamedStateKeepsItsName(): void
    {
        $ha = new RecordingHaContext();

        new Entities($ha)->light->getEntity('light.hall')->state('on');

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

        $hall = new Entities($ha)->light->getEntity('light.hall');
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
        self::assertNull(new Entities(new RecordingHaContext())->light->getEntity('light.hall')->getState());
        self::assertNull(LightState::fromNullableState(null));
    }

    public function testNumericDomainReadsNumber(): void
    {
        $ha = new RecordingHaContext()->seedState('sensor.hall_temperature', '21.4', ['battery' => 80]);
        $state = new Entities($ha)->sensor->getEntity('sensor.hall_temperature')->getState();

        self::assertNotNull($state);
        self::assertSame(21.4, $state->getStateAsFloat());
        self::assertSame(80.0, $state->getBattery());
    }

    public function testLookupKeepsMangledIdsApart(): void
    {
        $light = new Entities(new RecordingHaContext())->light;

        self::assertSame('light.porch_2', (string) $light->getEntity('light.porch_2')->id);
        self::assertSame('light.porch2', (string) $light->getEntity(new EntityId('light.porch2'))->id);
        self::assertSame('light', LightEntity::getDomain());
    }

    public function testUngeneratedIdIsRefused(): void
    {
        $light = new Entities(new RecordingHaContext())->light;

        $this->assertThrowsReason(IdentifierError::EntityNotGenerated, static fn() => $light->getEntity('light.hal'));
    }

    public function testOtherDomainIdIsRefused(): void
    {
        $this->assertThrowsReason(
            IdentifierError::EntityNotGenerated,
            static fn() => new LightEntity(new RecordingHaContext(), new EntityId('switch.fan')),
        );
    }

    public function testHandleDeliversOwnStateChanges(): void
    {
        $ha = new RecordingHaContext();
        $seen = [];

        new Entities($ha)->binarySensor->getEntity('binary_sensor.hall_motion')->watchStateChanges()
            ->whenChangedTo('on')
            ->subscribe(static function (StateChange $change) use (&$seen): void {
                $seen[] = $change->entityId->value;
            });

        $ha->pushState('binary_sensor.hall_motion', 'off');
        $ha->pushState('light.hall', 'on');
        $ha->pushState('binary_sensor.hall_motion', 'on');

        self::assertSame(['binary_sensor.hall_motion'], $seen);
    }

    public function testHandleReadsOwnHistory(): void
    {
        $ha = new RecordingHaContext()->seedState('light.hall', 'on');
        $ha->clock->skip(Duration::minutes(1));

        $history = new Entities($ha)->light->getEntity('light.hall')->getHistory(HistoryQuery::lastFor(Duration::minutes(5)));

        self::assertSame('light.hall', $history->entityId->value);
        self::assertTrue($history->hasBeenIn('on'));
    }

    public function testRootServiceTakesTargetOrEntity(): void
    {
        $ha = new RecordingHaContext();
        $services = new Services($ha);

        $services->light->turnOn(new Entities($ha)->light->getEntity('light.hall'), brightnessPct: 40);
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

        new Entities($ha)->light->getEntity('light.hall')->turnOn(white: true);

        self::assertSame(['white' => true], $ha->getLastCall()->data);
    }

    public function testIdleLightStillHasTypedAccessors(): void
    {
        $state = new Entities(new RecordingHaContext()->seedState('light.porch2', 'off', []))->light->getEntity('light.porch2')->requireState();

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
