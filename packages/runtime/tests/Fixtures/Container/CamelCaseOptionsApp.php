<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Container;

use Stewart\Contracts\App;

final readonly class CamelCaseOptionsApp implements App
{
    public function __construct(
        public string $motionSensor,
        public int $offDelaySeconds = 0,
    ) {}

    public function initialize(): void {}
}
