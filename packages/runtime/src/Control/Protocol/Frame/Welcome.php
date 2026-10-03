<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Frame;

final readonly class Welcome implements ServerFrame
{
    public function __construct(public int $protocol) {}
}
