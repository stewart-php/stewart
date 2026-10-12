<?php

declare(strict_types=1);

namespace Stewart\Runtime\Scope;

enum ScopePhase
{
    case Starting;
    case Live;
    case Closed;
}
