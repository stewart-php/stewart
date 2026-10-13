<?php

declare(strict_types=1);

namespace App;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Service\ServiceTarget;
use Stewart\Contracts\Time\Duration;

#[Automation(id: 'presence-heating')]
final readonly class PresenceHeating implements App
{
    /** @param list<string> $thermostats */
    public function __construct(
        private HaContext $ha,
        private string $household = 'group.family',
        private array $thermostats = ['climate.living_room', 'climate.bedroom'],
        private int $awayAfterMinutes = 15,
    ) {}

    public function initialize(): void
    {
        $this->ha->watchStateChanges($this->household)
            ->whenChangedTo('not_home', for: Duration::minutes($this->awayAfterMinutes))
            ->subscribe(fn() => $this->setPreset('away'));

        $this->ha->watchStateChanges($this->household)
            ->whenChangedTo('home', from: 'not_home')
            ->subscribe(fn() => $this->setPreset('home'));
    }

    public function dispose(): void {}

    private function setPreset(string $preset): void
    {
        $this->ha->callService('climate', 'set_preset_mode', ['preset_mode' => $preset], ServiceTarget::forEntities(...$this->thermostats));
    }
}
