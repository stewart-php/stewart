<?php

declare(strict_types=1);

namespace Stewart\Runtime\Http;

enum HttpListenerRole: string
{
    case Probe = 'probe';
    case Admin = 'admin';
    case Metrics = 'metrics';

    /** @return non-empty-list<non-empty-string> */
    public function listAllowedMethods(): array
    {
        return match ($this) {
            self::Probe, self::Metrics => ['GET', 'HEAD'],
            self::Admin => ['GET', 'HEAD', 'POST'],
        };
    }

    public function findOffLoopbackWarning(): ?string
    {
        return match ($this) {
            self::Admin => 'The HTTP admin API accepts connections from other hosts; anyone with the token can pause apps and reload',
            self::Probe, self::Metrics => null,
        };
    }
}
