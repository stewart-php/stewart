<?php

declare(strict_types=1);

namespace Stewart\Contracts\Schedule;

enum EntityTimeKind
{
    case Daily;
    case Moment;
    case None;
}
