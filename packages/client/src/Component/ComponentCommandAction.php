<?php

declare(strict_types=1);

namespace Stewart\Client\Component;

enum ComponentCommandAction: string
{
    case TurnOn = 'turn_on';
    case TurnOff = 'turn_off';
    case Press = 'press';
    case SetValue = 'set_value';
    case SelectOption = 'select_option';
}
