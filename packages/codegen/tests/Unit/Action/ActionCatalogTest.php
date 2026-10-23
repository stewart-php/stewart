<?php

declare(strict_types=1);

namespace Stewart\Codegen\Tests\Unit\Action;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Codegen\Action\ActionCatalog;
use Stewart\Codegen\Action\ActionCatalogParser;
use Stewart\Codegen\Action\ActionDefinition;
use Stewart\Codegen\Action\ActionField;
use Stewart\Codegen\Action\ActionTargetSpec;
use Stewart\Codegen\Action\Collection\ActionDefinitionCollection;
use Stewart\Codegen\Tests\Fixtures\GoldenSnapshot;

#[CoversClass(ActionCatalog::class)]
#[CoversClass(ActionCatalogParser::class)]
#[CoversClass(ActionDefinition::class)]
#[CoversClass(ActionField::class)]
#[CoversClass(ActionTargetSpec::class)]
final class ActionCatalogTest extends TestCase
{
    public function testDomainsAndActionsComeBackSorted(): void
    {
        $catalog = self::createCatalog();

        self::assertSame(['homeassistant', 'light', 'notify', 'persistent_notification', 'switch', 'weather'], $catalog->listDomains());
        self::assertSame(['state', 'turn_off', 'turn_on'], self::listActionNames($catalog->forDomain('light')));
    }

    public function testFlattensRequiredFirstOptionalByName(): void
    {
        $turnOn = self::createActionDefinition('light', 'turn_on');

        self::assertSame(
            ['brightness_pct', 'color_temp_kelvin', 'effect', 'flash', 'profile', 'rgb_color', 'transition', 'white'],
            array_map(static fn(ActionField $field): string => $field->name, $turnOn->fields),
        );

        $message = self::createActionDefinition('notify', 'mobile_app_phone')->fields[0] ?? null;

        self::assertNotNull($message);
        self::assertSame('message', $message->name);
        self::assertTrue($message->required);
    }

    public function testRequiredFieldsSortByNameWhateverHaOrder(): void
    {
        $catalog = new ActionCatalogParser()->parseServicesResponse(['demo' => ['run' => ['fields' => [
            'zeta' => ['required' => true],
            'beta' => ['required' => false],
            'alpha' => ['required' => true],
        ]]]]);

        self::assertSame(
            ['alpha', 'zeta', 'beta'],
            array_map(static fn(ActionField $field): string => $field->name, $catalog->forDomain('demo')->getFirst()->fields ?? []),
        );
    }

    public function testFieldWithoutASelectorIsUntyped(): void
    {
        $profile = self::createActionField('light', 'turn_on', 'profile');

        self::assertSame('mixed', $profile->type->native);
        self::assertNull($profile->min);
    }

    public function testSelectorsBoundsAndOptionsSurvive(): void
    {
        $transition = self::createActionField('light', 'turn_off', 'transition');

        self::assertSame(0, $transition->min);
        self::assertSame(300, $transition->max);
        self::assertSame('seconds', $transition->unit);

        self::assertSame("'long'|'short'", self::createActionField('light', 'turn_on', 'flash')->type->docblock);
        self::assertSame("'daily'|'hourly'", self::createActionField('weather', 'get_forecasts', 'type')->type->docblock);
    }

    public function testHandleGetsOwnDomainEntityActions(): void
    {
        $catalog = self::createCatalog();

        self::assertSame(
            ['state', 'turn_off', 'turn_on'],
            self::listActionNames($catalog->forEntityHandle('light')),
        );
        self::assertTrue($catalog->forEntityHandle('notify')->isEmpty());
    }

    public function testCrossDomainTargetStaysOffHandle(): void
    {
        $catalog = new ActionCatalogParser()->parseServicesResponse(['climate' => ['heat_the_light' => [
            'fields' => [],
            'target' => ['entity' => [['domain' => ['light']]]],
        ]]]);

        self::assertTrue($catalog->forEntityHandle('climate')->isEmpty());
        self::assertTrue($catalog->forEntityHandle('light')->isEmpty());
    }

    public function testAnyEntityActionStaysInOwnDomain(): void
    {
        $turnOn = self::createActionDefinition('homeassistant', 'turn_on');

        self::assertTrue($turnOn->target->isTargetable);
        self::assertSame([], $turnOn->target->entityDomains);
        self::assertTrue($turnOn->belongsOnHandle('homeassistant'));
        self::assertFalse($turnOn->belongsOnHandle('light'));
    }

    public function testNotifyActionCarriesNoTargetAtAll(): void
    {
        self::assertFalse(self::createActionDefinition('notify', 'mobile_app_phone')->target->isTargetable);
    }

    public function testActionThatAnswersIsMarked(): void
    {
        self::assertTrue(self::createActionDefinition('weather', 'get_forecasts')->returnsResponse);
        self::assertFalse(self::createActionDefinition('light', 'turn_on')->returnsResponse);
    }

    /** @return list<string> */
    private static function listActionNames(ActionDefinitionCollection $actions): array
    {
        return $actions->mapToList(static fn(ActionDefinition $action): string => $action->name);
    }

    private static function createCatalog(): ActionCatalog
    {
        return new ActionCatalogParser()->parseServicesResponse(GoldenSnapshot::loadSnapshot()->services);
    }

    private static function createActionDefinition(string $domain, string $name): ActionDefinition
    {
        foreach (self::createCatalog()->forDomain($domain) as $action) {
            if ($action->name === $name) {
                return $action;
            }
        }

        self::fail(\sprintf('The snapshot has no %s.%s action.', $domain, $name));
    }

    private static function createActionField(string $domain, string $action, string $name): ActionField
    {
        foreach (self::createActionDefinition($domain, $action)->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        self::fail(\sprintf('%s.%s has no %s field.', $domain, $action, $name));
    }
}
