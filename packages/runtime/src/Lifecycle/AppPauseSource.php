<?php

declare(strict_types=1);

namespace Stewart\Runtime\Lifecycle;

enum AppPauseSource: string
{
    case Config = 'config';
    case Control = 'control';
    case Http = 'http';
}
