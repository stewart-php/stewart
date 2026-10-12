<?php

declare(strict_types=1);

namespace Stewart\Testing\Tests\Fixtures\App;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Schedule\ScheduledTask;
use Stewart\Contracts\Schedule\Scheduler;
use Stewart\Contracts\Store\Store;
use Stewart\Contracts\Time\Duration;

#[Automation(id: 'hall-light')]
final class HallLightApp implements App
{
    private ?ScheduledTask $turnOff = null;

    public function __construct(
        private readonly HaContext $ha,
        private readonly Scheduler $scheduler,
        private readonly Store $store,
        private readonly LoggerInterface $logger,
        private readonly string $motionSensor,
        private readonly int $offAfterMinutes = 5,
    ) {}

    public function initialize(): void
    {
        $this->ha->watchStateChanges($this->motionSensor)->whenChangedTo('on')->subscribe(function (): void {
            $this->ha->callService('light', 'turn_on', ['entity_id' => 'light.hall']);
            $this->store->increment('motions');
            $this->logger->info('Motion in the hall');
            $this->turnOff?->cancel();
            $this->turnOff = $this->scheduler->runAfter(Duration::minutes($this->offAfterMinutes), function (): void {
                $this->ha->callService('light', 'turn_off', ['entity_id' => 'light.hall']);
            });
        });
    }

    public function dispose(): void {}
}
