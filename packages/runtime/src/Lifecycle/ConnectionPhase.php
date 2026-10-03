<?php

declare(strict_types=1);

namespace Stewart\Runtime\Lifecycle;

enum ConnectionPhase: string
{
    case Connecting = 'connecting';
    case Connected = 'connected';
    case Reconnecting = 'reconnecting';
}
