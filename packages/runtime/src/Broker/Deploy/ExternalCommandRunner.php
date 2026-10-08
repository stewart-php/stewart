<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Deploy;

use Amp\Cancellation;
use Amp\CancelledException;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Exception\DeployException;

interface ExternalCommandRunner
{
    /**
     * @param non-empty-list<string> $command
     * @throws DeployException
     * @throws CancelledException
     */
    public function runCommand(array $command, Duration $timeout, Cancellation $cancellation): ExternalCommandResult;
}
