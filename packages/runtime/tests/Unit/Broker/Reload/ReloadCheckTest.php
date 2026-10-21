<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker\Reload;

use Amp\NullCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Runtime\App\AppSelection;
use Stewart\Runtime\Broker\DaemonLaunchOptions;
use Stewart\Runtime\Broker\Reload\ReloadCheck;
use Stewart\Runtime\Broker\Reload\ReloadCheckApp;
use Stewart\Runtime\Broker\Reload\ReloadCheckReport;
use Stewart\Runtime\Broker\Reload\ReloadCheckReportCodec;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Deploy\ScriptedCommandRunner;

#[CoversClass(ReloadCheck::class)]
#[CoversClass(ReloadCheckReport::class)]
#[CoversClass(ReloadCheckReportCodec::class)]
final class ReloadCheckTest extends TestCase
{
    private const string CONSOLE_SCRIPT = '/project/vendor/bin/stewart';

    private ScriptedCommandRunner $commands;

    private ReloadCheckReportCodec $reports;

    protected function setUp(): void
    {
        $this->commands = new ScriptedCommandRunner();
        $this->reports = new ReloadCheckReportCodec(ReloadCheckReportCodec::createReportWireMapper());
    }

    public function testCheckRunsTheProjectConsoleWithTheLaunchOptionsAndNoXdebug(): void
    {
        $launch = new DaemonLaunchOptions('/project/custom.yaml', new AppSelection(AppIdCollection::fromList([new AppId('porch'), new AppId('hall')])));

        $this->createCheck($launch)->runReloadCheck(new NullCancellation());

        self::assertSame([\PHP_BINARY, self::CONSOLE_SCRIPT, 'reload:check', '--no-ansi', '--config=/project/custom.yaml', '--only=porch', '--only=hall'], $this->commands->commands[0]);
        self::assertSame(['XDEBUG_MODE' => 'off'], $this->commands->environmentOverrides[0]);
    }

    public function testReportOfTheChildIsReturned(): void
    {
        $report = new ReloadCheckReport([new ReloadCheckApp('porch', Demo::class)], [], [], 'fingerprint');
        $this->commands->queueResult(self::CONSOLE_SCRIPT, 0, $this->reports->encodeReport($report) . "\n");

        self::assertEquals($report, $this->createCheck()->runReloadCheck(new NullCancellation()));
    }

    public function testFailedReportKeepsItsErrors(): void
    {
        $this->commands->queueResult(self::CONSOLE_SCRIPT, 1, $this->reports->encodeReport(ReloadCheckReport::failed(['App "porch" cannot be built: missing option'], 'fingerprint')));

        self::assertSame('App "porch" cannot be built: missing option', $this->createCheck()->runReloadCheck(new NullCancellation())->findFirstError());
    }

    public function testChildWithoutAReportFailsWithItsFirstErrorLine(): void
    {
        $this->commands->queueResult(self::CONSOLE_SCRIPT, 255, '', "\nPHP Fatal error: Allowed memory size exhausted\n");

        self::assertSame('PHP Fatal error: Allowed memory size exhausted', $this->createCheck()->runReloadCheck(new NullCancellation())->findFirstError());
    }

    public function testSilentChildStillHasAReason(): void
    {
        $this->commands->queueResult(self::CONSOLE_SCRIPT, 1);

        self::assertSame('reload:check failed without saying why', $this->createCheck()->runReloadCheck(new NullCancellation())->findFirstError());
    }

    private function createCheck(DaemonLaunchOptions $launch = new DaemonLaunchOptions()): ReloadCheck
    {
        return new ReloadCheck($this->commands, $this->reports, $launch, self::CONSOLE_SCRIPT);
    }
}
