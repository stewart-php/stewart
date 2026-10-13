<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Wire;

use Stewart\Contracts\Exception\ExposureException;

final readonly class Circle extends Shape
{
    /** @throws ExposureException */
    public function __construct(
        public int|float $radius,
        string $name,
    ) {
        parent::__construct($name);

        if ($radius < 0) {
            throw ExposureException::configInvalid('A radius must not be negative.');
        }
    }
}
