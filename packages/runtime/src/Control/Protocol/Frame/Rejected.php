<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Frame;

final readonly class Rejected implements ServerFrame
{
    public function __construct(public string $reason) {}
}
