<?php

declare(strict_types=1);

namespace Stewart\Runtime\App;

enum AppPauseSource: string
{
    case Config = 'config';
    case Control = 'control';
}
