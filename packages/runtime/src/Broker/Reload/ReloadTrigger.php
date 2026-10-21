<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Reload;

enum ReloadTrigger: string
{
    case Signal = 'signal';
    case Control = 'control';
    case Http = 'http';
    case Watch = 'watch';
}
