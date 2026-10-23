<?php

declare(strict_types=1);

namespace Automations;

use Automations\Generated\Entities;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\Schedule\Scheduler;

#[Automation(id: 'lights-out')]
final readonly class LightsOut implements App
{
    /** @param list<string> $lights */
    public function __construct(
        private Entities $entities,
        private Scheduler $scheduler,
        private array $lights = ['light.hall', 'light.porch'],
    ) {}

    public function initialize(): void
    {
        $this->scheduler->runDailyAt('23:30', function (): void {
            foreach ($this->lights as $id) {
                $this->entities->light->getEntity($id)->turnOff();
            }
        });
    }
}
