<?php

declare(strict_types=1);

namespace App;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Service\ServiceTarget;

#[Automation(id: 'remote-toggle')]
final readonly class RemoteToggle implements App
{
    public function __construct(
        private HaContext $ha,
        private string $remote = '00:12:4b:00:24:c0:ff:ee',
        private string $light = 'light.living_room',
    ) {}

    public function initialize(): void
    {
        $this->ha->watchEvents('zha_event')
            ->filter(fn(HaEvent $event): bool => $event->getValue('device_ieee') === $this->remote)
            ->filter(static fn(HaEvent $event): bool => $event->getValue('command') === 'toggle')
            ->subscribe(function (): void {
                $this->ha->callService('light', 'toggle', target: ServiceTarget::forEntities($this->light));
            });
    }

    public function dispose(): void {}
}
