<?php

declare(strict_types=1);

namespace Stewart\Runtime\Http;

enum HttpListenerRole: string
{
    case Probe = 'probe';

    /** @return non-empty-list<non-empty-string> */
    public function listAllowedMethods(): array
    {
        return match ($this) {
            self::Probe => ['GET', 'HEAD'],
        };
    }
}
