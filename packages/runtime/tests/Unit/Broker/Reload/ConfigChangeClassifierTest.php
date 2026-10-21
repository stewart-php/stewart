<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker\Reload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Broker\Reload\ConfigChangeClassifier;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigFixture;

#[CoversClass(ConfigChangeClassifier::class)]
final class ConfigChangeClassifierTest extends TestCase
{
    public function testEverySettingIsEitherAppliedByAReloadOrWaitsForARestart(): void
    {
        $properties = array_keys(get_object_vars(ConfigFixture::createStewartConfig()));
        $classified = [...ConfigChangeClassifier::LIVE_SETTINGS, ...array_keys(ConfigChangeClassifier::SETTINGS_NEEDING_RESTART)];
        sort($properties);
        sort($classified);

        self::assertSame($properties, $classified, 'A new StewartConfig property has to say whether a reload applies it.');
    }

    public function testOnlySettingsOutsideAppsAndWorkersAreNamed(): void
    {
        $running = ConfigFixture::createStewartConfig();
        $reloaded = ConfigFixture::createStewartConfig([
            'workers' => 3,
            'log_level' => 'debug',
            'apps' => ['porch' => ['options' => ['light' => 'light.porch']]],
            'mqtt' => ['url' => 'mqtt://broker.invalid:1883'],
        ]);

        self::assertSame(['log_level', 'mqtt'], new ConfigChangeClassifier()->listSettingsNeedingRestart($running, $reloaded));
    }

    public function testSameConfigNeedsNoRestart(): void
    {
        self::assertSame([], new ConfigChangeClassifier()->listSettingsNeedingRestart(ConfigFixture::createStewartConfig(), ConfigFixture::createStewartConfig()));
    }
}
