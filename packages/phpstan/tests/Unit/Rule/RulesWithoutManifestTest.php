<?php

declare(strict_types=1);

namespace Stewart\PHPStan\Tests\Unit\Rule;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Stewart\PHPStan\Cache\ManifestResultCacheValue;
use Stewart\PHPStan\Manifest\ManifestCatalogLoader;
use Stewart\PHPStan\Rule\EntityIdArgumentRule;

/** @extends RuleTestCase<EntityIdArgumentRule> */
#[CoversClass(ManifestCatalogLoader::class)]
#[CoversClass(ManifestResultCacheValue::class)]
final class RulesWithoutManifestTest extends RuleTestCase
{
    public function testAProjectThatHasNotGeneratedYetIsNotChecked(): void
    {
        $this->analyse([__DIR__ . '/../../Fixtures/Analysed/EntityIdCalls.php'], []);
    }

    public function testTheResultCacheFollowsWhetherAManifestExists(): void
    {
        self::assertSame('none', self::getContainer()->getByType(ManifestResultCacheValue::class)->getValue(ManifestResultCacheValue::MANIFEST_KEY));
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../Fixtures/extension-without-manifest.neon'];
    }

    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(EntityIdArgumentRule::class);
    }
}
