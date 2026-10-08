<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Config\GitDeployConfig;
use Stewart\Runtime\Exception\ConfigurationError;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigFixture;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(GitDeployConfig::class)]
final class GitDeployConfigTest extends TestCase
{
    use AssertsReason;

    public function testPollingIsOffByDefault(): void
    {
        $gitDeploy = ConfigFixture::createStewartConfig()->gitDeploy;

        self::assertNull($gitDeploy->poll->findDuration());
        self::assertSame('10m', (string) $gitDeploy->prepareTimeout);
    }

    public function testPollIntervalIsParsed(): void
    {
        $gitDeploy = ConfigFixture::createStewartConfig(['deploy' => ['git' => ['poll' => '30s', 'prepare_timeout' => '5m']]])->gitDeploy;

        self::assertSame('30s', (string) $gitDeploy->poll->findDuration());
        self::assertSame('5m', (string) $gitDeploy->prepareTimeout);
    }

    public function testSubSecondPollIsRefused(): void
    {
        self::assertThrowsReason(ConfigurationError::DurationTooShort, static fn() => ConfigFixture::createStewartConfig(['deploy' => ['git' => ['poll' => '100ms']]]));
    }
}
