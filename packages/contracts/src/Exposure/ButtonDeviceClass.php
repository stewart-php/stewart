<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

enum ButtonDeviceClass: string
{
    case Identify = 'identify';
    case Restart = 'restart';
    case Update = 'update';
}
