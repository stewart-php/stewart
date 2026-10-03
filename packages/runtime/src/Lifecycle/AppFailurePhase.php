<?php

declare(strict_types=1);

namespace Stewart\Runtime\Lifecycle;

enum AppFailurePhase: string
{
    case Construct = 'construct';
    case Initialize = 'initialize';
    case Handler = 'handler';
    case Schedule = 'schedule';
    case Dispose = 'dispose';
}
