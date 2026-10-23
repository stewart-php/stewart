<?php

declare(strict_types=1);

namespace Automations;

use Automations\Support\QuietHours;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\State\StateChange;

#[Automation(id: 'battery-alert')]
final readonly class BatteryAlert implements App
{
    public function __construct(
        private HaContext $ha,
        private QuietHours $quietHours,
        private int $threshold = 20,
        private string $notifyService = 'notify',
    ) {}

    public function initialize(): void
    {
        $this->ha->watchStateChanges('sensor.*_battery')
            ->whenBelow($this->threshold)
            ->subscribe(function (StateChange $change): void {
                if ($this->quietHours->isNow()) {
                    return;
                }

                $this->ha->callAction('notify.' . $this->notifyService, [
                    'message' => \sprintf('%s is at %s%%.', $change->to?->getFriendlyName(), $change->to?->state),
                ]);
            });
    }
}
