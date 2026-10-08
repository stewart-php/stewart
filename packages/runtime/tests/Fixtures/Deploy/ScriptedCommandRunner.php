<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Deploy;

use Amp\Cancellation;
use Amp\CancelledException;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Broker\Deploy\ExternalCommandResult;
use Stewart\Runtime\Broker\Deploy\ExternalCommandRunner;
use Stewart\Testing\Async\Latch;

final class ScriptedCommandRunner implements ExternalCommandRunner
{
    /** @var list<non-empty-list<string>> */
    public array $commands = [];

    public bool $cancelled = false;

    public ?Latch $hold = null;

    /** @var array<string, list<ExternalCommandResult>> */
    private array $resultsBySubcommand = [];

    public function queueResult(string $subcommand, int $exitCode, string $output = '', string $errorOutput = ''): void
    {
        $this->resultsBySubcommand[$subcommand][] = new ExternalCommandResult($exitCode, $output, $errorOutput);
    }

    public function runCommand(array $command, Duration $timeout, Cancellation $cancellation): ExternalCommandResult
    {
        $this->commands[] = $command;

        try {
            $this->hold?->waitUntilOpen($cancellation);
        } catch (CancelledException $e) {
            $this->cancelled = true;

            throw $e;
        }

        $subcommand = $command[1] ?? '';
        $this->resultsBySubcommand[$subcommand] ??= [];

        return array_shift($this->resultsBySubcommand[$subcommand]) ?? new ExternalCommandResult(0, '', '');
    }

    /** @return list<string> */
    public function listSubcommands(): array
    {
        return array_map(static fn(array $command): string => $command[1] ?? '', $this->commands);
    }
}
