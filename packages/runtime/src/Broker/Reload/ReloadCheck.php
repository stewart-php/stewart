<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Reload;

use Amp\Cancellation;
use Amp\CancelledException;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Broker\DaemonLaunchOptions;
use Stewart\Runtime\Broker\Deploy\ExternalCommandRunner;
use Stewart\Runtime\Exception\DeployException;

final readonly class ReloadCheck
{
    private const int CHECK_TIMEOUT_SECONDS = 120;

    private const string UNEXPLAINED_FAILURE = 'stewart check failed without saying why';

    // An Xdebug session per reload would stall the check under make debug.
    private const array CHECK_ENVIRONMENT = ['XDEBUG_MODE' => 'off'];

    public function __construct(
        private ExternalCommandRunner $commands,
        private DaemonLaunchOptions $launch,
        private string $stewartConsoleScript,
    ) {}

    /** @throws CancelledException */
    public function findCheckFailure(Cancellation $cancellation): ?string
    {
        $command = [\PHP_BINARY, $this->stewartConsoleScript, 'check', '--no-ansi'];

        if ($this->launch->configFile !== null) {
            $command[] = '--config=' . $this->launch->configFile;
        }

        try {
            $result = $this->commands->runCommand($command, Duration::seconds(self::CHECK_TIMEOUT_SECONDS), $cancellation, self::CHECK_ENVIRONMENT);
        } catch (DeployException $e) {
            return $e->getMessage();
        }

        if ($result->isSuccessful()) {
            return null;
        }

        $reason = $result->findFirstErrorLine();

        return $reason === '' ? self::UNEXPLAINED_FAILURE : $reason;
    }
}
