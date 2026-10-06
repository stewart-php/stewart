<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Control\Client\ControlTarget;
use Stewart\Runtime\Control\Protocol\Frame\CommandResult;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: self::NAME, description: 'Forget the stored pause or resume of one app, so its config decides again')]
final class AppResetCommand extends AppRequestCommand
{
    public const string NAME = 'app:reset';

    protected function sendAppRequest(ControlTarget $target, AppId $appId, Duration $timeout): CommandResult
    {
        return $this->client->resetApp($target, $appId, $timeout);
    }
}
