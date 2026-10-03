<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Duration;

#[Automation(id: 'off-hold-caller')]
final readonly class OffHoldCaller implements App
{
    public function __construct(private HaContext $ha) {}

    public function initialize(): void
    {
        $this->ha->watchStateChanges('sensor.*')
            ->whenChangedTo('off', Duration::minutes(1))
            ->subscribe(function (StateChange $change): void {
                $this->ha->callService('script', 'record', ['entity' => $change->entityId->value]);
            });
    }

    public function dispose(): void {}
}
