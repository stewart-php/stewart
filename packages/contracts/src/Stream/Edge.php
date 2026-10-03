<?php

declare(strict_types=1);

namespace Stewart\Contracts\Stream;

enum Edge: string
{
    case Leading = 'leading';
    case Trailing = 'trailing';
    case Both = 'both';

    public function emitsLeading(): bool
    {
        return match ($this) {
            self::Leading, self::Both => true,
            self::Trailing => false,
        };
    }

    public function emitsTrailing(): bool
    {
        return match ($this) {
            self::Trailing, self::Both => true,
            self::Leading => false,
        };
    }
}
