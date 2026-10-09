<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

enum SwitchDeviceClass: string
{
    case Outlet = 'outlet';
    case Switch = 'switch';
}
