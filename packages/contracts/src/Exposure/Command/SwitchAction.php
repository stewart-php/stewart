<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure\Command;

enum SwitchAction: string
{
    case TurnOn = 'turn_on';
    case TurnOff = 'turn_off';
}
