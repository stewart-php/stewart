<?php

declare(strict_types=1);

namespace Stewart\PHPStan\Tests\Unit\Rule;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Stewart\PHPStan\Cache\ManifestResultCacheValue;
use Stewart\PHPStan\Manifest\GeneratedHandles;
use Stewart\PHPStan\Manifest\ManifestCatalog;
use Stewart\PHPStan\Manifest\ManifestCatalogLoader;
use Stewart\PHPStan\Rule\CalledMethod;
use Stewart\PHPStan\Rule\EntityIdArgumentRule;
use Stewart\PHPStan\Rule\EntityIdLiterals;
use Stewart\PHPStan\Rule\TestCodeScope;

/** @extends RuleTestCase<EntityIdArgumentRule> */
#[CoversClass(EntityIdArgumentRule::class)]
#[CoversClass(CalledMethod::class)]
#[CoversClass(EntityIdLiterals::class)]
#[CoversClass(TestCodeScope::class)]
#[CoversClass(ManifestCatalog::class)]
#[CoversClass(ManifestCatalogLoader::class)]
#[CoversClass(GeneratedHandles::class)]
#[CoversClass(ManifestResultCacheValue::class)]
final class EntityIdArgumentRuleTest extends RuleTestCase
{
    private const string UNKNOWN_TIP = 'Run make generate if the entity is new.';

    private const string ANALYSED = __DIR__ . '/../../Fixtures/Analysed/';

    public function testLiteralEntityIdsAreCheckedAgainstTheManifest(): void
    {
        $this->analyse([self::ANALYSED . 'EntityIdCalls.php'], [
            ['Entity ID "light.kitchn" was not in Home Assistant at the last stewart generate. Did you mean "light.kitchen"?', 26, self::UNKNOWN_TIP],
            ['Entity ID "switch.fna" was not in Home Assistant at the last stewart generate. Did you mean "switch.fan"?', 28, self::UNKNOWN_TIP],
            ['Entity ID "person.anna" was not in Home Assistant at the last stewart generate.', 32, self::UNKNOWN_TIP],
            ['Entity ID "zone.hom" was not in Home Assistant at the last stewart generate. Did you mean "zone.home"?', 32, self::UNKNOWN_TIP],
            ['Entity ID "input_datetime.wake_up" was not in Home Assistant at the last stewart generate.', 37, self::UNKNOWN_TIP],
            ['Entity ID "light.hal" was not in Home Assistant at the last stewart generate. Did you mean "light.hall"?', 38, self::UNKNOWN_TIP],
            ['Entity ID "light.kitchne" was not in Home Assistant at the last stewart generate. Did you mean "light.kitchen"?', 40, self::UNKNOWN_TIP],
            ['Entity ID "light.attic" is left out of the generated classes, so getEntity() throws EntityNotGenerated.', 46, 'Use HaContext::getEntity() for it, or include it in codegen.'],
            ['Entity ID "switch.fan" is not a light entity, so this getEntity() throws EntityNotGenerated.', 47],
            ['Entity ID "light.porch" was not in Home Assistant at the last stewart generate.', 48, self::UNKNOWN_TIP],
            ['Entity ID "light.kitchn" was not in Home Assistant at the last stewart generate. Did you mean "light.kitchen"?', 53, self::UNKNOWN_TIP],
        ]);
    }

    public function testTheResultCacheFollowsTheManifest(): void
    {
        self::assertNotSame('none', self::getContainer()->getByType(ManifestResultCacheValue::class)->getValue(ManifestResultCacheValue::MANIFEST_KEY));
    }

    public function testTestCodeIsNotChecked(): void
    {
        $this->analyse([self::ANALYSED . 'AppTestCalls.php'], []);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../Fixtures/extension-test.neon'];
    }

    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(EntityIdArgumentRule::class);
    }
}
