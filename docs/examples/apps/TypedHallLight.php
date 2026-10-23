<?php

declare(strict_types=1);

namespace Automations;

use Automations\Generated\Actions;
use Automations\Generated\Entities;
use Automations\Generated\LightState;
use Psr\Log\LoggerInterface;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\State\StateChange;

#[Automation(id: 'typed-hall-light')]
final readonly class TypedHallLight implements App
{
    public function __construct(
        private Entities $entities,
        private Actions $actions,
        private LoggerInterface $logger,
    ) {}

    public function initialize(): void
    {
        $hall = $this->entities->light->getEntity('light.hall');

        $this->entities->binarySensor->getEntity('binary_sensor.hall_motion')->watchStateChanges()
            ->whenChangedTo('on', from: 'off')
            ->subscribe(function () use ($hall): void {
                $hall->turnOn(brightnessPct: 70);
                $this->actions->notify->mobileAppPhone(message: 'Motion in the hall');
            });

        $hall->watchStateChanges()->subscribe(function (StateChange $change): void {
            $light = LightState::fromNullableState($change->to);

            if ($light?->isOn() === true) {
                $this->logger->info('Hall light is on', ['brightness' => $light->getBrightness()]);
            }
        });
    }
}
