<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker\Deploy;

use Amp\NullCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Broker\Deploy\CommitId;
use Stewart\Runtime\Broker\Deploy\ExternalCommandResult;
use Stewart\Runtime\Broker\Deploy\PreparedRelease;
use Stewart\Runtime\Broker\Deploy\ReleaseScript;
use Stewart\Runtime\Broker\Deploy\ReleaseState;
use Stewart\Runtime\Exception\DeployError;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigFixture;
use Stewart\Runtime\Tests\Fixtures\Deploy\ScriptedCommandRunner;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(ReleaseScript::class)]
#[CoversClass(PreparedRelease::class)]
#[CoversClass(ExternalCommandResult::class)]
final class ReleaseScriptTest extends TestCase
{
    use AssertsReason;

    private const string COMMIT = 'cccccccccccccccccccccccccccccccccccccccc';

    private ScriptedCommandRunner $commands;

    protected function setUp(): void
    {
        $this->commands = new ScriptedCommandRunner();
    }

    public function testPrepareReadsCommitAndState(): void
    {
        $this->commands->queueResult('release-prepare', 0, self::COMMIT . " prepared\n", "stewart-entrypoint: preparing\n");

        $release = $this->createScript()->prepareLatestRelease(new NullCancellation());

        self::assertSame(self::COMMIT, $release->commit->value);
        self::assertSame(ReleaseState::Prepared, $release->state);
    }

    public function testUnexpectedPrepareOutputIsRefused(): void
    {
        $this->commands->queueResult('release-prepare', 0, "checked out main\n");

        self::assertThrowsReason(DeployError::PrepareOutputUnexpected, fn() => $this->createScript()->prepareLatestRelease(new NullCancellation()));
    }

    public function testFailedPrepareReportsLastErrorLine(): void
    {
        $this->commands->queueResult('release-prepare', 1, '', "fatal: could not read from remote\nstewart-entrypoint: giving up\n\n");

        $e = self::assertThrowsReason(DeployError::CommandFailed, fn() => $this->createScript()->prepareLatestRelease(new NullCancellation()));

        self::assertStringEndsWith('stewart-entrypoint: giving up', $e->getMessage());
    }

    public function testCheckPassesDaemonConfigFile(): void
    {
        $this->createScript('/etc/stewart/stewart.yaml')->findCheckFailure(new CommitId(self::COMMIT), new NullCancellation());

        self::assertSame(['stewart-entrypoint', 'release-check', self::COMMIT, '--config=/etc/stewart/stewart.yaml'], $this->commands->commands[0]);
    }

    public function testCheckWithoutConfigFileNamesOnlyCommit(): void
    {
        self::assertNull($this->createScript()->findCheckFailure(new CommitId(self::COMMIT), new NullCancellation()));
        self::assertSame(['stewart-entrypoint', 'release-check', self::COMMIT], $this->commands->commands[0]);
    }

    public function testSilentCheckFailureStillHasReason(): void
    {
        $this->commands->queueResult('release-check', 1);

        self::assertSame('stewart check failed without saying why', $this->createScript()->findCheckFailure(new CommitId(self::COMMIT), new NullCancellation()));
    }

    private function createScript(?string $configFile = null): ReleaseScript
    {
        return new ReleaseScript($this->commands, ConfigFixture::createStewartConfig()->gitDeploy, $configFile);
    }
}
