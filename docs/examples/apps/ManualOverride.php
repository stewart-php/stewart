<?php

declare(strict_types=1);

namespace App;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Service\ServiceTarget;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;

#[Automation(id: 'manual-override')]
final class ManualOverride implements App
{
    private ?Instant $overriddenUntil = null;

    public function __construct(
        private readonly HaContext $ha,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly string $motionSensor = 'binary_sensor.hall_motion',
        private readonly string $light = 'light.hall',
    ) {}

    public function initialize(): void
    {
        $this->ha->watchStateChanges($this->light)
            ->filter(fn(StateChange $change): bool => !$this->ha->wasCausedByStewart($change))
            ->subscribe(function (): void {
                $this->overriddenUntil = $this->clock->getNow()->plus(Duration::hours(1));
                $this->logger->info('Someone changed the light by hand; leaving it alone for an hour');
            });

        $this->ha->watchStateChanges($this->motionSensor)
            ->whenChangedTo('on')
            ->subscribe(function (): void {
                if ($this->overriddenUntil?->isAfter($this->clock->getNow()) === true) {
                    return;
                }

                $this->ha->callService('light', 'turn_on', target: ServiceTarget::forEntities($this->light));
            });
    }
}
