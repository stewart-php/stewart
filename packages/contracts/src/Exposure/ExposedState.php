<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

final readonly class ExposedState
{
    public function __construct(public int|float|string|bool|null $value) {}
}
