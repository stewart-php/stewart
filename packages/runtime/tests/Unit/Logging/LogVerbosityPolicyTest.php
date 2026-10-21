<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Logging;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Runtime\Logging\LogVerbosityPolicy;
use Stewart\Runtime\Model\AppVerbosity;
use Stewart\Runtime\Model\LogLevel;
use Stewart\Runtime\Model\ResourceScope;

#[CoversClass(LogVerbosityPolicy::class)]
final class LogVerbosityPolicyTest extends TestCase
{
    public function testRecordsWithoutAnAppFollowTheGlobalLevel(): void
    {
        $verbosity = new LogVerbosityPolicy(LogLevel::Info, ['porch' => new AppVerbosity(LogLevel::Debug)]);

        self::assertFalse($verbosity->admitsLevel(LogLevel::Debug, null));
        self::assertFalse($verbosity->admitsLevel(LogLevel::Debug, ResourceScope::shared()));
        self::assertTrue($verbosity->admitsLevel(LogLevel::Info, null));
    }

    public function testAnAppLevelOverridesTheGlobalOneBothWays(): void
    {
        $verbosity = new LogVerbosityPolicy(LogLevel::Info, [
            'porch' => new AppVerbosity(LogLevel::Debug),
            'chatty' => new AppVerbosity(LogLevel::Warning),
            'hall' => new AppVerbosity(),
        ]);

        self::assertTrue($verbosity->admitsLevel(LogLevel::Debug, $this->scopeFor('porch')));
        self::assertFalse($verbosity->admitsLevel(LogLevel::Info, $this->scopeFor('chatty')));
        self::assertFalse($verbosity->admitsLevel(LogLevel::Debug, $this->scopeFor('hall')));
        self::assertTrue($verbosity->admitsLevel(LogLevel::Info, $this->scopeFor('unknown')));
    }

    public function testReplacedVerbositiesApplyToTheNextRecord(): void
    {
        $verbosity = new LogVerbosityPolicy(LogLevel::Info, ['porch' => new AppVerbosity(LogLevel::Debug)]);

        $verbosity->replaceAppVerbosities(['hall' => new AppVerbosity(LogLevel::Debug)]);

        self::assertFalse($verbosity->admitsLevel(LogLevel::Debug, $this->scopeFor('porch')));
        self::assertTrue($verbosity->admitsLevel(LogLevel::Debug, $this->scopeFor('hall')));
    }

    private function scopeFor(string $appId): ResourceScope
    {
        return ResourceScope::forApp(new AppId($appId));
    }
}
