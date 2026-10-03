<?php

declare(strict_types=1);

namespace Stewart\Contracts\Event;

enum EventOrigin: string
{
    // Home Assistant's own spelling on the wire; every other enum is snake_case.
    case Local = 'LOCAL';
    case Remote = 'REMOTE';
}
