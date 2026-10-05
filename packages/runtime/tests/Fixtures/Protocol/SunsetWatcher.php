<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Trigger\HaTrigger;
use Stewart\Contracts\Trigger\TriggerEvent;

#[Automation(id: 'sunset-watcher')]
final readonly class SunsetWatcher implements App
{
    public const array REJECTED_TRIGGER = ['trigger' => 'nonsense'];

    public function __construct(private HaContext $ha) {}

    public function initialize(): void
    {
        $this->ha->watchTrigger(HaTrigger::onSunset())->subscribe(function (TriggerEvent $event): void {
            $this->ha->callService('light', 'turn_on', ['trigger' => $event->getTriggerId()]);
        });
        $this->ha->watchTrigger(self::REJECTED_TRIGGER)->subscribe(static function (): void {});
    }

    public function dispose(): void {}
}
