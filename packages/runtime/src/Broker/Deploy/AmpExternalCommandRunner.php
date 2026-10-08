<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Deploy;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\Future;
use Amp\Process\Process;
use Amp\TimeoutCancellation;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Exception\DeployException;

use function Amp\async;
use function Amp\ByteStream\buffer;

final readonly class AmpExternalCommandRunner implements ExternalCommandRunner
{
    public function runCommand(array $command, Duration $timeout, Cancellation $cancellation): ExternalCommandResult
    {
        $process = Process::start($command);
        $process->getStdin()->close();
        // Both pipes drain while the command runs, so a chatty composer install cannot fill one and stall.
        /** @var Future<string> $output */
        $output = async(static fn(): string => buffer($process->getStdout()));
        /** @var Future<string> $errorOutput */
        $errorOutput = async(static fn(): string => buffer($process->getStderr()));

        try {
            $exitCode = $process->join(new CompositeCancellation($cancellation, new TimeoutCancellation($timeout->toSeconds())));
        } catch (CancelledException $e) {
            $process->kill();

            if ($cancellation->isRequested()) {
                throw $e;
            }

            throw DeployException::commandTimedOut(implode(' ', $command), $timeout, $e);
        }

        return new ExternalCommandResult($exitCode, $output->await(), $errorOutput->await());
    }
}
