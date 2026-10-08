<?php

declare(strict_types=1);

namespace Stewart\Runtime\Lifecycle;

enum BrokerStopOutcome
{
    case Stopped;
    case RestartRequested;
}
