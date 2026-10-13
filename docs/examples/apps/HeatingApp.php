<?php

declare(strict_types=1);

namespace App;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Store\Store;

#[Automation(id: 'heating')]
final class HeatingApp implements App
{
    public function __construct(
        private readonly HaContext $ha,
        private readonly Store $store,
    ) {}

    public function initialize(): void
    {
        $this->ha->watchStateChanges('binary_sensor.front_door')->whenChangedTo('on')->subscribe(function (): void {
            $this->store->increment('opens');
        });
    }

    public function dispose(): void {}
}
