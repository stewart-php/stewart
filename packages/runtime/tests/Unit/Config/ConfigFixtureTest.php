<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Config\ConfigSection;
use Stewart\Runtime\Config\StewartConfig;
use Stewart\Runtime\Config\StewartConfigSchema;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigFixture;
use Symfony\Component\Config\Definition\Processor;

#[CoversNothing]
final class ConfigFixtureTest extends TestCase
{
    public function testFixtureDefaultsAreTheSchemaDefaults(): void
    {
        $processed = new Processor()->process(new StewartConfigSchema()->buildConfigTree(), [['home_assistant' => ConfigFixture::HOME_ASSISTANT]]);

        self::assertEquals(StewartConfig::fromSection(ConfigSection::forRoot($processed)), ConfigFixture::createStewartConfig());
    }

    public function testSectionAccessorOverridesOnlyWhatItIsGiven(): void
    {
        $supervision = ConfigFixture::createSupervisionConfig(['restart_attempts' => 3]);

        self::assertSame(3, $supervision->restartAttempts);
        self::assertEquals(ConfigFixture::createStewartConfig()->supervision->restartWindow, $supervision->restartWindow);
    }
}
