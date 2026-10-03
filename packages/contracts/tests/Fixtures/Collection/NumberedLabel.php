<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Fixtures\Collection;

final readonly class NumberedLabel
{
    public function __construct(
        public int $number,
        public string $label,
    ) {}
}
