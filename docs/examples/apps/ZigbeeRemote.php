<?php

declare(strict_types=1);

namespace Automations;

use Stewart\Contracts\Action\ActionTarget;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Mqtt\Mqtt;
use Stewart\Contracts\Mqtt\MqttMessage;

#[Automation(id: 'zigbee-remote')]
final readonly class ZigbeeRemote implements App
{
    public function __construct(
        private HaContext $ha,
        private Mqtt $mqtt,
        private string $remote = 'living_room_remote',
        private string $light = 'light.living_room',
    ) {}

    public function initialize(): void
    {
        $this->mqtt->watchMessages("zigbee2mqtt/{$this->remote}/action")->subscribe(function (MqttMessage $message): void {
            [$action, $data] = match ($message->payload) {
                'on' => ['light.turn_on', []],
                'off' => ['light.turn_off', []],
                'brightness_up' => ['light.turn_on', ['brightness_step_pct' => 10]],
                'brightness_down' => ['light.turn_on', ['brightness_step_pct' => -10]],
                default => [null, []],
            };

            if ($action !== null) {
                $this->ha->callAction($action, $data, ActionTarget::forEntities($this->light));
            }
        });
    }
}
