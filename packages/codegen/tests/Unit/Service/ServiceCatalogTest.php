<?php

declare(strict_types=1);

namespace Stewart\Codegen\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Codegen\Service\Collection\ServiceDefinitionCollection;
use Stewart\Codegen\Service\ServiceCatalog;
use Stewart\Codegen\Service\ServiceCatalogParser;
use Stewart\Codegen\Service\ServiceDefinition;
use Stewart\Codegen\Service\ServiceField;
use Stewart\Codegen\Service\ServiceTargetSpec;
use Stewart\Codegen\Tests\Fixtures\GoldenSnapshot;

#[CoversClass(ServiceCatalog::class)]
#[CoversClass(ServiceCatalogParser::class)]
#[CoversClass(ServiceDefinition::class)]
#[CoversClass(ServiceField::class)]
#[CoversClass(ServiceTargetSpec::class)]
final class ServiceCatalogTest extends TestCase
{
    public function testDomainsAndServicesComeBackSorted(): void
    {
        $catalog = self::createCatalog();

        self::assertSame(['homeassistant', 'light', 'notify', 'switch', 'weather'], $catalog->listDomains());
        self::assertSame(['state', 'turn_off', 'turn_on'], self::listServiceNames($catalog->forDomain('light')));
    }

    public function testFlattensRequiredFirstOptionalByName(): void
    {
        $turnOn = self::createServiceDefinition('light', 'turn_on');

        self::assertSame(
            ['brightness_pct', 'color_temp_kelvin', 'effect', 'flash', 'profile', 'rgb_color', 'transition', 'white'],
            $turnOn->fields->mapToList(static fn(ServiceField $field): string => $field->name),
        );

        $message = self::createServiceDefinition('notify', 'mobile_app_phone')->fields->getFirst();

        self::assertNotNull($message);
        self::assertSame('message', $message->name);
        self::assertTrue($message->required);
    }

    public function testRequiredFieldsSortByNameWhateverHaOrder(): void
    {
        $catalog = new ServiceCatalogParser()->parseServicesResponse(['demo' => ['run' => ['fields' => [
            'zeta' => ['required' => true],
            'beta' => ['required' => false],
            'alpha' => ['required' => true],
        ]]]]);

        self::assertSame(
            ['alpha', 'zeta', 'beta'],
            $catalog->forDomain('demo')->getFirst()?->fields->mapToList(static fn(ServiceField $field): string => $field->name),
        );
    }

    public function testFieldWithoutASelectorIsUntyped(): void
    {
        $profile = self::createServiceField('light', 'turn_on', 'profile');

        self::assertSame('mixed', $profile->type->native);
        self::assertNull($profile->min);
    }

    public function testSelectorsBoundsAndOptionsSurvive(): void
    {
        $transition = self::createServiceField('light', 'turn_off', 'transition');

        self::assertSame(0, $transition->min);
        self::assertSame(300, $transition->max);
        self::assertSame('seconds', $transition->unit);

        self::assertSame("'long'|'short'", self::createServiceField('light', 'turn_on', 'flash')->type->docblock);
        self::assertSame("'daily'|'hourly'", self::createServiceField('weather', 'get_forecasts', 'type')->type->docblock);
    }

    public function testHandleGetsOwnDomainEntityServices(): void
    {
        $catalog = self::createCatalog();

        self::assertSame(
            ['state', 'turn_off', 'turn_on'],
            self::listServiceNames($catalog->forEntityHandle('light')),
        );
        self::assertTrue($catalog->forEntityHandle('notify')->isEmpty());
    }

    public function testCrossDomainTargetStaysOffHandle(): void
    {
        $catalog = new ServiceCatalogParser()->parseServicesResponse(['climate' => ['heat_the_light' => [
            'fields' => [],
            'target' => ['entity' => [['domain' => ['light']]]],
        ]]]);

        self::assertTrue($catalog->forEntityHandle('climate')->isEmpty());
        self::assertTrue($catalog->forEntityHandle('light')->isEmpty());
    }

    public function testAnyEntityServiceStaysInOwnDomain(): void
    {
        $turnOn = self::createServiceDefinition('homeassistant', 'turn_on');

        self::assertTrue($turnOn->target->isTargetable);
        self::assertSame([], $turnOn->target->entityDomains);
        self::assertTrue($turnOn->belongsOnHandle('homeassistant'));
        self::assertFalse($turnOn->belongsOnHandle('light'));
    }

    public function testNotifyServiceCarriesNoTargetAtAll(): void
    {
        self::assertFalse(self::createServiceDefinition('notify', 'mobile_app_phone')->target->isTargetable);
    }

    public function testServiceThatAnswersIsMarked(): void
    {
        self::assertTrue(self::createServiceDefinition('weather', 'get_forecasts')->returnsResponse);
        self::assertFalse(self::createServiceDefinition('light', 'turn_on')->returnsResponse);
    }

    /** @return list<string> */
    private static function listServiceNames(ServiceDefinitionCollection $services): array
    {
        return $services->mapToList(static fn(ServiceDefinition $service): string => $service->name);
    }

    private static function createCatalog(): ServiceCatalog
    {
        return new ServiceCatalogParser()->parseServicesResponse(GoldenSnapshot::loadSnapshot()->services);
    }

    private static function createServiceDefinition(string $domain, string $name): ServiceDefinition
    {
        foreach (self::createCatalog()->forDomain($domain) as $service) {
            if ($service->name === $name) {
                return $service;
            }
        }

        self::fail(\sprintf('The snapshot has no %s.%s service.', $domain, $name));
    }

    private static function createServiceField(string $domain, string $service, string $name): ServiceField
    {
        foreach (self::createServiceDefinition($domain, $service)->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        self::fail(\sprintf('%s.%s has no %s field.', $domain, $service, $name));
    }
}
