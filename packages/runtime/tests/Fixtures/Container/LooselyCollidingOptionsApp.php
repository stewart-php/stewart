<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Container;

use Stewart\Contracts\App;

final readonly class LooselyCollidingOptionsApp implements App
{
    public function __construct(
        public string $motionSensor = '',
        public string $motion_sensor = '',
    ) {}

    public function initialize(): void {}

    public function dispose(): void {}
}
