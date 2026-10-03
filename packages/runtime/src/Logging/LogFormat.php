<?php

declare(strict_types=1);

namespace Stewart\Runtime\Logging;

enum LogFormat: string
{
    case Line = 'line';
    case Json = 'json';
}
