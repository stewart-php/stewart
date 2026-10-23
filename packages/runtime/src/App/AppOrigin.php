<?php

declare(strict_types=1);

namespace Stewart\Runtime\App;

enum AppOrigin: string
{
    case Scanned = 'scanned';
    case Configured = 'configured';
}
