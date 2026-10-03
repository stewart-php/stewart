<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console;

use Stewart\Runtime\Exception\ConfigurationException;

enum ProbeKind: string
{
    case Liveness = 'liveness';
    case Readiness = 'readiness';

    /** @throws ConfigurationException */
    public static function parse(string $raw): self
    {
        return self::tryFrom($raw) ?? throw ConfigurationException::valueInvalid($raw, 'liveness or readiness');
    }
}
