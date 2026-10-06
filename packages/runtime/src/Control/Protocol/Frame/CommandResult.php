<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Frame;

final readonly class CommandResult implements ServerFrame
{
    public function __construct(
        public bool $changed,
        public string $message,
    ) {}
}
