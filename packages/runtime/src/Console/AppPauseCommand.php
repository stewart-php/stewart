<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Control\Client\ControlTarget;
use Stewart\Runtime\Control\Protocol\Frame\CommandResult;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: self::NAME, description: 'Pause one app: it stays loaded but skips deliveries and schedules until resumed')]
final class AppPauseCommand extends AppRequestCommand
{
    public const string NAME = 'app:pause';

    protected function sendAppRequest(ControlTarget $target, AppId $appId, Duration $timeout): CommandResult
    {
        return $this->client->pauseApp($target, $appId, $timeout);
    }
}
