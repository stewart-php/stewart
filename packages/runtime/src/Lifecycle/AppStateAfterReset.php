<?php

declare(strict_types=1);

namespace Stewart\Runtime\Lifecycle;

enum AppStateAfterReset
{
    case PausedByConfig;
    case Running;
    case NotLoaded;
}
