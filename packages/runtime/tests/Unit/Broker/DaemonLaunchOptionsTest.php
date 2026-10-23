<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Broker\DaemonLaunchOptions;

#[CoversClass(DaemonLaunchOptions::class)]
final class DaemonLaunchOptionsTest extends TestCase
{
    public function testNoOptionOverridesNothing(): void
    {
        self::assertSame([], new DaemonLaunchOptions()->listConfigOverrides());
    }

    public function testDryRunAndLogLevelOverrideTheirSettings(): void
    {
        self::assertSame(
            ['log_level' => 'debug', 'ha_calls' => ['dry_run' => true]],
            new DaemonLaunchOptions(logLevel: 'debug', dryRun: true)->listConfigOverrides(),
        );
    }
}
