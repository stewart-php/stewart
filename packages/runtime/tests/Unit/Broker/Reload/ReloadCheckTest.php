<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker\Reload;

use Amp\NullCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Broker\DaemonLaunchOptions;
use Stewart\Runtime\Broker\Reload\ReloadCheck;
use Stewart\Runtime\Tests\Fixtures\Deploy\ScriptedCommandRunner;

#[CoversClass(ReloadCheck::class)]
final class ReloadCheckTest extends TestCase
{
    private const string CONSOLE_SCRIPT = '/project/vendor/bin/stewart';

    private ScriptedCommandRunner $commands;

    protected function setUp(): void
    {
        $this->commands = new ScriptedCommandRunner();
    }

    public function testCheckRunsTheProjectConsoleWithoutXdebug(): void
    {
        self::assertNull($this->createCheck('/project/custom.yaml')->findCheckFailure(new NullCancellation()));

        self::assertSame([\PHP_BINARY, self::CONSOLE_SCRIPT, 'check', '--no-ansi', '--config=/project/custom.yaml'], $this->commands->commands[0]);
        self::assertSame(['XDEBUG_MODE' => 'off'], $this->commands->environmentOverrides[0]);
    }

    public function testDefaultConfigNeedsNoOption(): void
    {
        $this->createCheck()->findCheckFailure(new NullCancellation());

        self::assertSame([\PHP_BINARY, self::CONSOLE_SCRIPT, 'check', '--no-ansi'], $this->commands->commands[0]);
    }

    public function testFailureIsTheFirstErrorLine(): void
    {
        $this->commands->queueResult(self::CONSOLE_SCRIPT, 1, '', "\nApp \"porch\" cannot be built: missing option\nApp \"hall\" cannot be built: missing option\n");

        self::assertSame('App "porch" cannot be built: missing option', $this->createCheck()->findCheckFailure(new NullCancellation()));
    }

    public function testSilentFailureStillHasAReason(): void
    {
        $this->commands->queueResult(self::CONSOLE_SCRIPT, 1);

        self::assertSame('stewart check failed without saying why', $this->createCheck()->findCheckFailure(new NullCancellation()));
    }

    private function createCheck(?string $configFile = null): ReloadCheck
    {
        return new ReloadCheck($this->commands, new DaemonLaunchOptions($configFile), self::CONSOLE_SCRIPT);
    }
}
