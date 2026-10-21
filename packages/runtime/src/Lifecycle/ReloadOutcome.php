<?php

declare(strict_types=1);

namespace Stewart\Runtime\Lifecycle;

enum ReloadOutcome: string
{
    case Applied = 'applied';
    case PartlyApplied = 'partly_applied';
    case Failed = 'failed';

    public function describeOutcome(): string
    {
        return match ($this) {
            self::Applied => 'applied',
            self::PartlyApplied => 'partly applied',
            self::Failed => 'failed',
        };
    }
}
