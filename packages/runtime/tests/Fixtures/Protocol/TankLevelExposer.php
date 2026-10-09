<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\Exposure\EntityExposure;
use Stewart\Contracts\Exposure\SensorConfig;

#[Automation(id: 'tank-level')]
final readonly class TankLevelExposer implements App
{
    public const int LEVEL = 42;

    public function __construct(private EntityExposure $entities) {}

    public function initialize(): void
    {
        $this->entities->exposeSensor('level', new SensorConfig(unit: '%', name: 'Tank level'))->setValue(self::LEVEL);
    }

    public function dispose(): void {}
}
