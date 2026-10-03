<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Wire;

final readonly class Label
{
    public function __construct(public string $text) {}
}
