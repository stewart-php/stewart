<?php

declare(strict_types=1);

namespace Automations;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\Action\ActionTarget;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Schedule\Scheduler;
use Stewart\Contracts\Sun\SunEvent;

#[Automation(id: 'porch-light')]
final readonly class PorchLight implements App
{
    public function __construct(
        private HaContext $ha,
        private Scheduler $scheduler,
        private LoggerInterface $logger,
        private string $light = 'light.porch',
        private string $offAt = '23:00',
    ) {}

    public function initialize(): void
    {
        $this->scheduler->runAtSunEvent(SunEvent::Sunset, function (): void {
            $this->logger->info('Sunset, turning the porch light on');
            $this->ha->callAction('light.turn_on', target: ActionTarget::forEntities($this->light));
        });

        $this->scheduler->runDailyAt($this->offAt, function (): void {
            $this->logger->info('Bedtime, turning the porch light off');
            $this->ha->callAction('light.turn_off', target: ActionTarget::forEntities($this->light));
        });
    }
}
