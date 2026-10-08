<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Deploy;

use Amp\Cancellation;
use Amp\CancelledException;
use Stewart\Runtime\Config\GitDeployConfig;
use Stewart\Runtime\Exception\DeployException;

final readonly class ReleaseScript
{
    private const string ENTRYPOINT = 'stewart-entrypoint';

    private const string UNEXPLAINED_CHECK_FAILURE = 'stewart check failed without saying why';

    public function __construct(
        private ExternalCommandRunner $commands,
        private GitDeployConfig $gitDeploy,
        private ?string $releaseCheckConfigFile = null,
    ) {}

    /**
     * @throws DeployException
     * @throws CancelledException
     */
    public function prepareLatestRelease(Cancellation $cancellation): PreparedRelease
    {
        return PreparedRelease::fromScriptOutput($this->runEntrypoint(['release-prepare'], $cancellation)->output);
    }

    /**
     * @throws DeployException
     * @throws CancelledException
     */
    public function findCheckFailure(CommitId $commit, Cancellation $cancellation): ?string
    {
        $arguments = ['release-check', $commit->value];

        if ($this->releaseCheckConfigFile !== null) {
            $arguments[] = '--config=' . $this->releaseCheckConfigFile;
        }

        $result = $this->commands->runCommand([self::ENTRYPOINT, ...$arguments], $this->gitDeploy->prepareTimeout, $cancellation);

        if ($result->isSuccessful()) {
            return null;
        }

        $reason = $result->findFirstErrorLine();

        return $reason === '' ? self::UNEXPLAINED_CHECK_FAILURE : $reason;
    }

    /**
     * @throws DeployException
     * @throws CancelledException
     */
    public function stageRelease(CommitId $commit, Cancellation $cancellation): void
    {
        $this->runEntrypoint(['release-stage', $commit->value], $cancellation);
    }

    /**
     * @param list<string> $arguments
     * @throws DeployException
     * @throws CancelledException
     */
    private function runEntrypoint(array $arguments, Cancellation $cancellation): ExternalCommandResult
    {
        $command = [self::ENTRYPOINT, ...$arguments];
        $result = $this->commands->runCommand($command, $this->gitDeploy->prepareTimeout, $cancellation);

        if (!$result->isSuccessful()) {
            throw DeployException::commandFailed(implode(' ', $command), $result->exitCode, $result->findLastErrorLine());
        }

        return $result;
    }
}
