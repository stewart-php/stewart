<?php

declare(strict_types=1);

namespace Stewart\Runtime\Model;

enum ExposedCommandOutcome: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Refused = 'refused';
    case Lost = 'lost';
}
