<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Control\Client\ControlTarget;
use Stewart\Runtime\Control\Protocol\Frame\CommandResult;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: self::NAME, description: 'Resume a paused app')]
final class AppResumeCommand extends AppRequestCommand
{
    public const string NAME = 'app:resume';

    protected function sendAppRequest(ControlTarget $target, AppId $appId, Duration $timeout): CommandResult
    {
        return $this->client->resumeApp($target, $appId, $timeout);
    }
}
