<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Wire;

final readonly class NotPromoted
{
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}
