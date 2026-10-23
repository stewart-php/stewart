<?php

declare(strict_types=1);

namespace Stewart\PHPStan\Tests\Unit\Rule;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Stewart\PHPStan\Manifest\GeneratedHandles;
use Stewart\PHPStan\Rule\ActionNameArgumentRule;
use Stewart\PHPStan\Rule\EntityIdArgumentRule;

// The fixture tree's Manifest and handle are fatal to load, so a rule that loads them ends the test process.
/** @extends RuleTestCase<EntityIdArgumentRule|ActionNameArgumentRule> */
#[CoversClass(GeneratedHandles::class)]
final class RulesOnOlderMajorTreeTest extends RuleTestCase
{
    /** @var class-string<EntityIdArgumentRule|ActionNameArgumentRule> */
    private string $ruleClass = EntityIdArgumentRule::class;

    public function testEntityIdsGoUncheckedWithoutLoadingTheTree(): void
    {
        $this->analyse([__DIR__ . '/../../Fixtures/Analysed/OlderMajorCalls.php'], []);
    }

    public function testActionNamesGoUncheckedWithoutLoadingTheTree(): void
    {
        $this->ruleClass = ActionNameArgumentRule::class;

        $this->analyse([__DIR__ . '/../../Fixtures/Analysed/OlderMajorCalls.php'], []);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../Fixtures/extension-older-major.neon'];
    }

    protected function getRule(): Rule
    {
        return self::getContainer()->getByType($this->ruleClass);
    }
}
