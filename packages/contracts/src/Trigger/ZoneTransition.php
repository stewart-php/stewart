<?php

declare(strict_types=1);

namespace Stewart\Contracts\Trigger;

enum ZoneTransition: string
{
    case Enter = 'enter';
    case Leave = 'leave';
}
