<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Deploy;

enum ReleaseState: string
{
    case Current = 'current';
    case Rejected = 'rejected';
    case Prepared = 'prepared';
}
