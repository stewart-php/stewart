<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Config\SupervisionConfig;
use Stewart\Runtime\Exception\ConfigurationError;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigFixture;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(SupervisionConfig::class)]
final class SupervisionConfigTest extends TestCase
{
    use AssertsReason;

    public function testWindowEqualToBackoffSumIsRefused(): void
    {
        $e = $this->assertThrowsReason(ConfigurationError::RestartWindowTooShort, fn() => ConfigFixture::createSupervisionConfig(['restart_window' => '31s']));

        self::assertStringContainsString('the backoff for restart_attempts (5) adds up to 31s', $e->getMessage());
    }

    public function testWindowJustOverBackoffSumIsAccepted(): void
    {
        $supervision = ConfigFixture::createSupervisionConfig(['restart_window' => '31001ms']);

        self::assertEquals(Duration::milliseconds(31_001), $supervision->restartWindow);
    }

    public function testZeroAttemptsNeedNoLongWindow(): void
    {
        $supervision = ConfigFixture::createSupervisionConfig(['restart_attempts' => 0, 'restart_window' => '1ms']);

        self::assertSame(0, $supervision->restartAttempts);
    }
}
