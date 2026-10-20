<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console\Doctor;

enum DoctorOutcome
{
    case Pass;
    case Warn;
    case Skip;
    case Fail;

    public function getMark(): string
    {
        return match ($this) {
            self::Pass => '✓',
            self::Warn => '! warn',
            self::Skip => '– skip',
            self::Fail => '✗ FAIL',
        };
    }
}
