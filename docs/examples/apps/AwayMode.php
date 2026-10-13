<?php

declare(strict_types=1);

namespace App;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Store\GlobalStore;
use Stewart\Contracts\Store\Store;

#[Automation(id: 'away-mode')]
final readonly class AwayMode implements App
{
    public function __construct(
        private HaContext $ha,
        #[GlobalStore]
        private Store $house,
        private string $family = 'group.family',
    ) {}

    public function initialize(): void
    {
        $this->ha->watchStateChanges($this->family)->whenChangedTo('not_home')->subscribe(function (): void {
            $this->house->set('mode', 'away');
        });

        $this->ha->watchStateChanges($this->family)->whenChangedTo('home')->subscribe(function (): void {
            $this->house->set('mode', 'home');
        });
    }

    public function dispose(): void {}
}
