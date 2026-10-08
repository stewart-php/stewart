<?php

declare(strict_types=1);

namespace Stewart\Runtime\Exception;

use Stewart\Contracts\Exception\ExceptionReason;

enum DeployError: string implements ExceptionReason
{
    case CommitIdInvalid = 'commit_id_invalid';
    case CommandTimedOut = 'command_timed_out';
    case CommandFailed = 'command_failed';
    case PrepareOutputUnexpected = 'prepare_output_unexpected';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::CommitIdInvalid => '"{value}" is not a commit hash.',
            self::CommandTimedOut => '{command} did not finish within {timeout}.',
            self::CommandFailed => '{command} failed with exit code {exitCode}: {detail}',
            self::PrepareOutputUnexpected => 'release-prepare printed "{output}" instead of a commit and its state.',
        };
    }
}
