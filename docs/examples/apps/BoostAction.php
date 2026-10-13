<?php

declare(strict_types=1);

namespace App;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\Exposure\Action\ActionCall;
use Stewart\Contracts\Exposure\Action\ActionConfig;
use Stewart\Contracts\Exposure\Action\ActionField;
use Stewart\Contracts\Exposure\Action\ActionFieldType;
use Stewart\Contracts\Exposure\EntityExposure;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Schedule\Scheduler;
use Stewart\Contracts\Service\ServiceTarget;
use Stewart\Contracts\Time\Duration;

#[Automation(id: 'heating')]
final readonly class BoostAction implements App
{
    public function __construct(
        private HaContext $ha,
        private EntityExposure $entities,
        private Scheduler $scheduler,
    ) {}

    public function initialize(): void
    {
        $boost = $this->entities->exposeAction('boost', new ActionConfig(
            name: 'Boost',
            description: 'Heats a room at full power for a while.',
            fields: [
                new ActionField('room', ActionFieldType::Entity, required: true),
                new ActionField('minutes', ActionFieldType::Number, description: '30 when left out.'),
            ],
        ));

        $boost->watchCalls()->subscribe(function (ActionCall $call): void {
            $room = $call->findEntityId('room');

            if ($room === null) {
                return;
            }

            $target = ServiceTarget::forEntities($room);
            $this->ha->callService('climate', 'set_preset_mode', ['preset_mode' => 'boost'], $target);
            $this->scheduler->runAfter(Duration::minutes((int) ($call->findNumber('minutes') ?? 30)), function () use ($target): void {
                $this->ha->callService('climate', 'set_preset_mode', ['preset_mode' => 'comfort'], $target);
            });
        });
    }

    public function dispose(): void {}
}
