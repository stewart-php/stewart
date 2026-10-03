<?php

declare(strict_types=1);

namespace Stewart\Client\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Client\HaConfig;
use Stewart\Client\HaCoreState;

#[CoversClass(HaConfig::class)]
final class HaConfigTest extends TestCase
{
    public function testParsesTimeZone(): void
    {
        $config = HaConfig::fromGetConfigResult(['time_zone' => 'Europe/Budapest', 'unit_system' => []]);

        self::assertSame('Europe/Budapest', $config->timeZoneName);
        self::assertSame('Europe/Budapest', $config->timeZone?->getName());
    }

    public function testKeepsNameOfUnknownTimeZone(): void
    {
        $config = HaConfig::fromGetConfigResult(['time_zone' => 'Mars/Olympus']);

        self::assertSame('Mars/Olympus', $config->timeZoneName);
        self::assertNull($config->timeZone);
    }

    public function testMissingTimeZone(): void
    {
        $config = HaConfig::fromGetConfigResult(['time_zone' => '']);

        self::assertNull($config->timeZoneName);
        self::assertNull($config->timeZone);
    }

    public function testCoreStateIsRead(): void
    {
        self::assertSame(HaCoreState::Starting, HaConfig::fromGetConfigResult(['state' => 'STARTING'])->coreState);
        self::assertSame(HaCoreState::Running, HaConfig::fromGetConfigResult(['time_zone' => 'UTC', 'state' => 'RUNNING'])->coreState);
    }

    public function testUnknownCoreStateReadsAsNull(): void
    {
        self::assertNull(HaConfig::fromGetConfigResult(['state' => 'WARMING_UP'])->coreState);
        self::assertNull(HaConfig::fromGetConfigResult(['state' => 3])->coreState);
        self::assertNull(HaConfig::fromGetConfigResult([])->coreState);
    }
}
