<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Reload;

use Amp\Cancellation;
use Amp\CancelledException;
use JsonException;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Broker\DaemonLaunchOptions;
use Stewart\Runtime\Broker\Deploy\ExternalCommandRunner;
use Stewart\Runtime\Exception\DeployException;

final readonly class ReloadCheck
{
    private const string COMMAND = 'reload:check';

    private const int CHECK_TIMEOUT_SECONDS = 120;

    private const string UNEXPLAINED_FAILURE = 'reload:check failed without saying why';

    // An Xdebug session per reload would stall the check under make debug.
    private const array CHECK_ENVIRONMENT = ['XDEBUG_MODE' => 'off'];

    public function __construct(
        private ExternalCommandRunner $commands,
        private ReloadCheckReportCodec $reports,
        private DaemonLaunchOptions $launch,
        private string $stewartConsoleScript,
    ) {}

    /** @throws CancelledException */
    public function runReloadCheck(Cancellation $cancellation): ReloadCheckReport
    {
        try {
            $result = $this->commands->runCommand($this->buildCommand(), Duration::seconds(self::CHECK_TIMEOUT_SECONDS), $cancellation, self::CHECK_ENVIRONMENT);
        } catch (DeployException $e) {
            return ReloadCheckReport::failed([$e->getMessage()]);
        }

        try {
            return $this->reports->decodeReport(trim($result->output));
        } catch (JsonException|StewartException) {
            $reason = $result->findFirstErrorLine();

            return ReloadCheckReport::failed([$reason === '' ? self::UNEXPLAINED_FAILURE : $reason]);
        }
    }

    /** @return non-empty-list<string> */
    private function buildCommand(): array
    {
        $command = [\PHP_BINARY, $this->stewartConsoleScript, self::COMMAND, '--no-ansi'];

        if ($this->launch->configFile !== null) {
            $command[] = '--config=' . $this->launch->configFile;
        }

        foreach ($this->launch->selection->onlyIds ?? [] as $appId) {
            $command[] = '--only=' . $appId->value;
        }

        return $command;
    }
}
