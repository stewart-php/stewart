<?php

declare(strict_types=1);

namespace Automations;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\Disposable;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\State\StateChange;

#[Automation(id: 'energy-log')]
final class EnergyLog implements App, Disposable
{
    /** @var resource|null */
    private $file = null;

    public function __construct(
        private readonly HaContext $ha,
        private readonly string $path = '/app/var/power.log',
        private readonly string $meter = 'sensor.house_power',
    ) {}

    public function initialize(): void
    {
        $this->file = fopen($this->path, 'a') ?: null;

        $this->ha->watchStateChanges($this->meter)->subscribe(function (StateChange $change): void {
            if ($this->file !== null && $change->to !== null) {
                fwrite($this->file, $change->to->state . "\n");
            }
        });
    }

    public function dispose(): void
    {
        if ($this->file !== null) {
            fclose($this->file);
            $this->file = null;
        }
    }
}
