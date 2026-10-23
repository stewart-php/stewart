<?php

declare(strict_types=1);

namespace Stewart\PHPStan\Tests\Unit\Rule;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Stewart\PHPStan\Rule\ActionNameArgumentRule;

/** @extends RuleTestCase<ActionNameArgumentRule> */
#[CoversClass(ActionNameArgumentRule::class)]
final class ActionNameArgumentRuleTest extends RuleTestCase
{
    private const string UNKNOWN_TIP = 'Run make generate if the action is new, such as one from a script or an integration added since.';

    private const string ANALYSED = __DIR__ . '/../../Fixtures/Analysed/';

    public function testLiteralActionNamesAreCheckedAgainstTheManifest(): void
    {
        $this->analyse([self::ANALYSED . 'ActionCalls.php'], [
            ['Action "light.turn_onn" was not in Home Assistant at the last stewart generate. Did you mean "light.turn_on"?', 23, self::UNKNOWN_TIP],
            ['Action "weather.get_forecasts" was not in Home Assistant at the last stewart generate.', 24, self::UNKNOWN_TIP],
            ['"turn_on" is not an action name; write it as domain.action, such as light.turn_on.', 25],
            ['Action "notify.notfy" was not in Home Assistant at the last stewart generate. Did you mean "notify.notify"?', 26, self::UNKNOWN_TIP],
            ['Action "light.toggle" was not in Home Assistant at the last stewart generate.', 33, self::UNKNOWN_TIP],
            ['Action "light.turn_onn" was not in Home Assistant at the last stewart generate. Did you mean "light.turn_on"?', 34, self::UNKNOWN_TIP],
            ['Entity::callAction() takes the action alone, such as "turn_on", not "light.turn_on".', 36],
        ]);
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
        return self::getContainer()->getByType(ActionNameArgumentRule::class);
    }
}
