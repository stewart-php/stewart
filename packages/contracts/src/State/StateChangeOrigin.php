<?php

declare(strict_types=1);

namespace Stewart\Contracts\State;

enum StateChangeOrigin: string
{
    case Live = 'live';
    case Resync = 'resync';
}
